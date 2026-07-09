<?php

namespace Tests\Feature\Queue;

use App\Models\Content;
use App\Models\MediaFile;
use App\Models\SearchIndexState;
use App\Models\WorkflowInstance;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\Worker\WorkerRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STALE 재색인 루프 — SM Spec §14 (INDEXED→STALE→INDEXED) · Queue Worker Spec §12 (재색인 priority 50).
 * Scheduler 틱이 STALE 상태에 단독 INDEX job(is_required=false)을 생성하고,
 * INDEX worker 실행으로 STALE→INDEXED가 소진되는지 검증한다.
 */
class StaleReindexTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowSchedulerService $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->scheduler = app(WorkflowSchedulerService::class);
    }

    /** @return array{0: Content, 1: WorkflowInstance, 2: SearchIndexState} */
    private function makeStaleContent(string $instanceStatus = 'SUCCESS'): array
    {
        $content = Content::factory()->create(['status' => 'READY']);
        MediaFile::factory()->for($content)->create();
        $instance = WorkflowInstance::factory()->for($content, 'content')->create(['status' => $instanceStatus]);
        $state = SearchIndexState::create([
            'content_id' => $content->id, 'status' => 'STALE',
            'index_version' => 1, 'index_doc_id' => "content-{$content->id}",
        ]);

        return [$content, $instance, $state];
    }

    public function test_tick_creates_single_reindex_job_for_stale_state(): void
    {
        [$content] = $this->makeStaleContent();

        $counts = $this->scheduler->tick();
        $this->assertSame(1, $counts['reindex']);

        $job = WorkflowJob::query()
            ->where('content_id', $content->id)->where('job_type', 'INDEX')->firstOrFail();
        $this->assertSame('WAITING', $job->status->value);
        $this->assertFalse((bool) $job->is_required); // 재색인 실패는 콘텐츠 비차단 (SM Spec §7)
        $this->assertSame(50, (int) $job->priority);  // 재색인 우선순위 (Queue Worker Spec §12)
        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $job->id, 'to_status' => 'WAITING', 'note' => 'stale_reindex',
        ]);

        // 다음 틱 — 의존 없음 + 인스턴스 SUCCESS라도 READY 승격, 중복 생성은 없다
        $counts = $this->scheduler->tick();
        $this->assertSame(0, $counts['reindex']);
        $this->assertSame('READY', $job->fresh()->status->value);
        $this->assertSame(1, WorkflowJob::query()
            ->where('content_id', $content->id)->where('job_type', 'INDEX')->count());
    }

    public function test_active_index_job_blocks_duplicate_creation(): void
    {
        [$content, $instance] = $this->makeStaleContent();
        WorkflowJob::factory()->create([
            'instance_id' => $instance->id, 'content_id' => $content->id,
            'job_type' => 'INDEX', 'status' => 'READY',
        ]);

        $counts = $this->scheduler->tick();

        $this->assertSame(0, $counts['reindex']);
        $this->assertSame(1, WorkflowJob::query()
            ->where('content_id', $content->id)->where('job_type', 'INDEX')->count());
    }

    public function test_no_reindex_job_without_promotable_instance(): void
    {
        [$content] = $this->makeStaleContent(instanceStatus: 'FAILED');

        $counts = $this->scheduler->tick();

        $this->assertSame(0, $counts['reindex']);
        $this->assertSame(0, WorkflowJob::query()
            ->where('content_id', $content->id)->where('job_type', 'INDEX')->count());
    }

    public function test_reindex_worker_run_consumes_stale_back_to_indexed(): void
    {
        [$content, , $state] = $this->makeStaleContent();

        $this->scheduler->tick(); // 생성
        $this->scheduler->tick(); // READY 승격

        $worker = WorkflowWorkerAgent::factory()->online()->create([
            'supported_job_types' => ['INDEX'],
        ]);
        app(WorkerRunner::class)->run($worker, once: true);

        $fresh = $state->fresh();
        $this->assertSame('INDEXED', $fresh->status->value);
        $this->assertSame(2, (int) $fresh->index_version);
        $this->assertSame('SUCCESS', WorkflowJob::query()
            ->where('content_id', $content->id)->where('job_type', 'INDEX')
            ->firstOrFail()->status->value);
    }
}
