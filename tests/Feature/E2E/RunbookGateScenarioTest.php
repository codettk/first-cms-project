<?php

namespace Tests\Feature\E2E;

use App\Enums\ContentStatus;
use App\Enums\FailureType;
use App\Enums\InstanceStatus;
use App\Enums\JobType;
use App\Models\Content;
use App\Models\WorkflowJob;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Admin\Query\DashboardQueryService;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\Search\SearchIndexClient;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\StateMachine\SearchIndexStateMachine;
use App\Services\Workflow\StateMachine\WorkflowInstanceStateMachine;
use App\Services\Workflow\Worker\HandlerRegistry;
use App\Services\Workflow\Worker\Handlers\IndexJobHandler;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\WorkerRunner;
use App\Services\Workflow\Worker\WorkflowJobHandler;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * M5 운영 게이트 — Runbook 장애 리허설 잔여 시나리오 (Roadmap Phase 8).
 * ② Storage 차단 → STORAGE_ERROR 재시도 → 복구 후 자동 SUCCESS (Runbook §6-10)
 * ⑥ 검색엔진 중단 → INDEX 백오프 재시도 → 엔진 복구 후 자동 SUCCESS (Runbook §6-15 · 시나리오 T6)
 * ④ 필수 job 최종 실패 → SKIPPED 전파 → contents FAILED + 대시보드 HIGH 배너 (Runbook §3 SEV 판정)
 * 부하 T5 — 동시 업로드 10건 → 이중 실행 0건 · 전건 READY (MVP Scope §T5)
 *
 * ① Worker kill·③ Scheduler 정지·fencing은 RunbookScenarioTest,
 * ⑤ Lock/타임아웃 회수는 SchedulerTest, ⑦ 취소 정리는 AdminCommandApiTest·SchedulerTest가 커버한다.
 */
class RunbookGateScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry);
    }

    /** trigger 허용 경로를 따라 상태를 강제 설정 (테스트 픽스처 전용) */
    private function forceStatus(WorkflowJob $job, string ...$path): void
    {
        foreach ($path as $status) {
            DB::table('workflow_jobs')->where('id', $job->id)->update(['status' => $status]);
        }
    }

    private function resumeRetry(WorkflowJob $job, WorkflowSchedulerService $scheduler): void
    {
        // 트랜잭션 고정 now() 대비 충분히 과거로 — claim SQL의 next_attempt_at <= now()도 통과해야 한다
        DB::table('workflow_jobs')->where('id', $job->id)->update(['next_attempt_at' => now()->subMinutes(10)]);
        $scheduler->tick();
    }

    public function test_storage_outage_retries_then_recovers_automatically(): void
    {
        // Runbook §6-10 — Storage I/O 오류는 STORAGE_ERROR 재시도, zone 복구 후 자동 성공
        $storage = new class implements WorkflowJobHandler
        {
            public bool $zoneUp = false;

            public int $attempts = 0;

            public function supports(string $jobType): bool
            {
                return $jobType === 'TM';
            }

            public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
            {
                $this->attempts++;

                return $this->zoneUp
                    ? JobResult::success(['stored' => true])
                    : JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'MASTER zone mount unavailable');
            }

            public function cancel(WorkflowJob $job): void {}
        };
        app(HandlerRegistry::class)->register($storage);

        $job = WorkflowJob::factory()->ready()->create(['max_retry' => 3]);
        $worker = WorkflowWorkerAgent::factory()->online()->create(['supported_job_types' => ['TM']]);
        $scheduler = app(WorkflowSchedulerService::class);
        $runner = app(WorkerRunner::class);

        // 1차 시도 — 차단 상태: RETRY + 백오프 예약
        $runner->run($worker, once: true);
        $fresh = $job->fresh();
        $this->assertSame('RETRY', $fresh->status->value);
        $this->assertSame('STORAGE_IO', $fresh->fail_reason_code);
        $this->assertNotNull($fresh->next_attempt_at);

        // 재개 후 2차 시도 — 여전히 차단: 다시 RETRY (재시도 소진 아님)
        $this->resumeRetry($job, $scheduler);
        $this->assertSame(1, $job->fresh()->retry_count);
        $runner->run($worker, once: true);
        $this->assertSame('RETRY', $job->fresh()->status->value);

        // Runbook 절차: zone 복구 → 별도 조작 없이 재개만으로 SUCCESS
        $storage->zoneUp = true;
        $this->resumeRetry($job, $scheduler);
        $runner->run($worker, once: true);

        $fresh = $job->fresh();
        $this->assertSame('SUCCESS', $fresh->status->value);
        $this->assertSame(3, $storage->attempts);
        $this->assertSame(2, $fresh->retry_count);
    }

    public function test_search_engine_outage_backs_off_then_recovers_automatically(): void
    {
        // Runbook §6-15 / T6 — 실제 IndexJobHandler + 엔진 stub으로 중단·복구를 재현한다
        $engine = new class implements SearchIndexClient
        {
            public bool $up = false;

            /** @var array<string, array<string, mixed>> */
            public array $documents = [];

            public function upsert(string $documentId, array $document): void
            {
                if (! $this->up) {
                    throw new RuntimeException('search engine unreachable');
                }
                $this->documents[$documentId] = $document;
            }

            public function get(string $documentId): ?array
            {
                if (! $this->up) {
                    throw new RuntimeException('search engine unreachable');
                }

                return $this->documents[$documentId] ?? null;
            }
        };
        app(HandlerRegistry::class)->register(new IndexJobHandler(
            $engine, app(SearchIndexStateMachine::class), app(\App\Services\Workflow\Storage\MediaStorageService::class),
        ));

        $job = WorkflowJob::factory()->ready()->ofType(JobType::Index)->create(['max_retry' => 5]);
        $worker = WorkflowWorkerAgent::factory()->online()->create(['supported_job_types' => ['INDEX']]);
        $scheduler = app(WorkflowSchedulerService::class);
        $runner = app(WorkerRunner::class);

        // 엔진 중단 — INDEX_UNAVAILABLE로 RETRY (SEARCH_ENGINE_ERROR base 30초 백오프)
        $runner->run($worker, once: true);
        $fresh = $job->fresh();
        $this->assertSame('RETRY', $fresh->status->value);
        $this->assertSame('INDEX_UNAVAILABLE', $fresh->fail_reason_code);
        $this->assertNotNull($fresh->next_attempt_at);

        // 여전히 중단 — 재시도가 소진 없이 백오프를 반복한다
        $this->resumeRetry($job, $scheduler);
        $runner->run($worker, once: true);
        $this->assertSame('RETRY', $job->fresh()->status->value);
        $this->assertSame(1, $job->fresh()->retry_count);

        // 엔진 복구 — 수동 개입 없이 자동 SUCCESS + INDEXED 회복 (Runbook §20 체크 항목)
        $engine->up = true;
        $this->resumeRetry($job, $scheduler);
        $runner->run($worker, once: true);

        $fresh = $job->fresh();
        $this->assertSame('SUCCESS', $fresh->status->value);
        $this->assertSame("content-{$job->content_id}", $fresh->result['index_doc_id']);
        $this->assertSame('INDEXED', DB::table('search_index_states')
            ->where('content_id', $job->content_id)->value('status'));
        $this->assertArrayHasKey("content-{$job->content_id}", $engine->documents);
    }

    public function test_required_failure_fails_content_and_raises_dashboard_banner(): void
    {
        // Runbook §3 — 필수 job 최종 실패는 콘텐츠 FAILED + HIGH 알림(배너) 대상
        $content = Content::factory()->processing()->create();
        $template = WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();
        $instance = DB::transaction(
            fn () => app(WorkflowInstanceFactory::class)->createForContent($content, $template)
        );

        $jobOf = fn (string $type): WorkflowJob => $instance->jobs()->where('job_type', $type)->firstOrFail();

        foreach (['TM', 'VERIFY', 'MA'] as $type) {
            $this->forceStatus($jobOf($type), 'READY', 'RUNNING', 'SUCCESS');
        }
        // TC 최종 실패 (PERMANENT — 재시도 소진과 동일한 종결)
        $this->forceStatus($jobOf('TC'), 'READY', 'RUNNING', 'FAILED');

        app(WorkflowSchedulerService::class)->tick();

        // 실패 전파 — 후속 필수 job SKIPPED · instance/contents FAILED (SM Spec §12)
        foreach (['CA', 'INDEX', 'PUBLISH'] as $type) {
            $this->assertSame('SKIPPED', $jobOf($type)->fresh()->status->value, "{$type}가 SKIPPED가 아니다");
        }
        $this->assertSame(InstanceStatus::Failed, $instance->fresh()->status);
        $this->assertSame(ContentStatus::Failed, $content->fresh()->status);

        // 판정/알림 — 대시보드가 실시간 파생 HIGH 배너를 노출한다 (ADR-0002)
        $banners = app(DashboardQueryService::class)->aggregate(refresh: true)['banners'];
        $failureBanners = array_values(array_filter($banners, fn (array $b) => $b['event_type'] === 'required_job_failed'));

        $this->assertNotEmpty($failureBanners, '필수 실패 배너가 노출되지 않았다');
        $this->assertSame('HIGH', $failureBanners[0]['severity']);
    }

    public function test_ten_concurrent_uploads_all_reach_ready_without_double_execution(): void
    {
        // T5 부하 — 동시 업로드 10건(80 job)을 Worker 3대가 소비: 이중 실행 0건 · 전건 READY
        $executions = new \ArrayObject;

        $genericHandler = function (string $type) use ($executions): WorkflowJobHandler {
            return new class($type, $executions) implements WorkflowJobHandler
            {
                public function __construct(
                    private readonly string $type,
                    private readonly \ArrayObject $executions,
                ) {}

                public function supports(string $jobType): bool
                {
                    return $jobType === $this->type;
                }

                public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
                {
                    $this->executions[$job->id] = ($this->executions[$job->id] ?? 0) + 1;

                    return JobResult::success(['done' => $this->type]);
                }

                public function cancel(WorkflowJob $job): void {}
            };
        };

        $registry = app(HandlerRegistry::class);
        foreach (['TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX', 'CLEANUP'] as $type) {
            $registry->register($genericHandler($type));
        }

        // PUBLISH는 실제 게시 의미를 유지 — StateMachine 경유로만 READY 전환 (파일 검증은 VideoPipelineTest가 커버)
        $registry->register(new class($executions) implements WorkflowJobHandler
        {
            public function __construct(private readonly \ArrayObject $executions) {}

            public function supports(string $jobType): bool
            {
                return $jobType === 'PUBLISH';
            }

            public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
            {
                $this->executions[$job->id] = ($this->executions[$job->id] ?? 0) + 1;

                return DB::transaction(function () use ($job, $ctx) {
                    app(ContentStateMachine::class)->transition(
                        $job->content, ContentStatus::Ready->value, "worker:{$ctx->worker->id}", 'publish',
                        ['published_at' => now()],
                    );
                    app(WorkflowInstanceStateMachine::class)->transition(
                        $job->instance, InstanceStatus::Success->value, "worker:{$ctx->worker->id}", 'publish',
                        ['finished_at' => now()],
                    );

                    return JobResult::success(['published' => true]);
                });
            }

            public function cancel(WorkflowJob $job): void {}
        });

        $template = WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();
        $contents = Content::factory()->processing()->count(10)->create();

        foreach ($contents as $content) {
            DB::transaction(fn () => app(WorkflowInstanceFactory::class)->createForContent($content, $template));
        }

        $allTypes = ['TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'];
        $workers = WorkflowWorkerAgent::factory()->online()->count(3)
            ->create(['supported_job_types' => $allTypes]);

        $scheduler = app(WorkflowSchedulerService::class);
        $runner = app(WorkerRunner::class);

        $allDone = fn (): bool => Content::whereIn('id', $contents->pluck('id'))
            ->where('status', 'READY')->count() === 10
            && DB::table('workflow_jobs')->whereIn('content_id', $contents->pluck('id'))
                ->where('status', '<>', 'SUCCESS')->count() === 0;

        for ($i = 0; $i < 60 && ! $allDone(); $i++) {
            $scheduler->tick();
            foreach ($workers as $worker) {
                $runner->run($worker, once: true);
            }
        }

        $this->assertTrue($allDone(), '10건 전부 READY/SUCCESS에 도달하지 못했다: '.
            json_encode(Content::whereIn('id', $contents->pluck('id'))->pluck('status', 'id')));

        // 80 job 전건 SUCCESS + 각 job은 정확히 1회 실행 (이중 claim/실행 0건)
        $jobs = DB::table('workflow_jobs')->whereIn('content_id', $contents->pluck('id'))->get();
        $this->assertCount(80, $jobs);
        $this->assertSame(80, $jobs->where('status', 'SUCCESS')->count());

        $counts = $executions->getArrayCopy();
        $this->assertCount(80, $counts, '실행되지 않은 job이 있다');
        $this->assertSame([1], array_values(array_unique($counts)), '이중 실행이 감지되었다');
    }
}
