<?php

namespace Tests\Feature\Queue;

use App\Enums\FailureType;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Worker\HandlerRegistry;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\WorkerRunner;
use App\Services\Workflow\Worker\WorkflowJobHandler;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Worker Agent Spec §5 — claim → execute → finalize 루프 · 실패 분류 · drain shutdown.
 */
class WorkerRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // 실제 MVP 핸들러 대신 테스트 전용 페이크만 등록되도록 빈 레지스트리로 교체
        $this->app->instance(HandlerRegistry::class, new HandlerRegistry);
    }

    private function registerHandler(string $type, Closure $handle): void
    {
        app(HandlerRegistry::class)->register(new class($type, $handle) implements WorkflowJobHandler
        {
            public function __construct(
                private readonly string $type,
                private readonly Closure $callback,
            ) {}

            public function supports(string $jobType): bool
            {
                return $jobType === $this->type;
            }

            public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
            {
                return ($this->callback)($job, $ctx);
            }

            public function cancel(WorkflowJob $job): void {}
        });
    }

    private function makeAgentAndJob(array $jobAttributes = []): array
    {
        $agent = WorkflowWorkerAgent::factory()->online()->create([
            'supported_job_types' => ['TM'],
        ]);
        $job = WorkflowJob::factory()->ready()->create($jobAttributes);

        return [$agent, $job];
    }

    public function test_successful_job_is_finalized_with_result(): void
    {
        [$agent, $job] = $this->makeAgentAndJob();
        $this->registerHandler('TM', fn () => JobResult::success(['moved' => true]));

        $processed = app(WorkerRunner::class)->run($agent, once: true);

        $this->assertSame(1, $processed);
        $fresh = $job->fresh();
        $this->assertSame('SUCCESS', $fresh->status->value);
        $this->assertSame(['moved' => true], $fresh->result);
        $this->assertNotNull($fresh->started_at);
        $this->assertNotNull($fresh->finished_at);
        $this->assertSame($agent->id, $fresh->worker_id);
        $this->assertDatabaseMissing('workflow_job_locks', ['job_id' => $job->id]);
        // claim(READY→RUNNING) + 완료(RUNNING→SUCCESS) 이력
        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $job->id, 'from_status' => 'READY', 'to_status' => 'RUNNING', 'note' => 'claimed',
        ]);
        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $job->id, 'from_status' => 'RUNNING', 'to_status' => 'SUCCESS',
        ]);
        // run() 종료 시 graceful shutdown — OFFLINE + current_job 정리
        $freshAgent = $agent->fresh();
        $this->assertSame('OFFLINE', $freshAgent->status->value);
        $this->assertNull($freshAgent->current_job_id);
    }

    public function test_transient_failure_goes_retry_with_backoff(): void
    {
        [$agent, $job] = $this->makeAgentAndJob();
        $this->registerHandler('TM', fn () => JobResult::failure(
            FailureType::Transient, 'NETWORK_ERROR', 'connection reset'
        ));

        app(WorkerRunner::class)->run($agent, once: true);

        $fresh = $job->fresh();
        $this->assertSame('RETRY', $fresh->status->value);
        $this->assertSame('NETWORK_ERROR', $fresh->fail_reason_code);
        $this->assertNotNull($fresh->next_attempt_at);
        $this->assertSame(0, $fresh->retry_count); // 증가는 Scheduler 재개 시 (ADR-0003)
    }

    public function test_permanent_failure_goes_failed_immediately(): void
    {
        [$agent, $job] = $this->makeAgentAndJob();
        $this->registerHandler('TM', fn () => JobResult::failure(
            FailureType::Permanent, 'PERMANENT_CORRUPT', 'broken file'
        ));

        app(WorkerRunner::class)->run($agent, once: true);

        $fresh = $job->fresh();
        $this->assertSame('FAILED', $fresh->status->value);
        $this->assertSame('PERMANENT_CORRUPT', $fresh->fail_reason_code);
        $this->assertNotNull($fresh->finished_at);
        $this->assertDatabaseHas('workflow_job_logs', [
            'job_id' => $job->id, 'level' => 'ERROR',
        ]);
    }

    public function test_handler_exception_is_classified_as_system_error_and_retried(): void
    {
        [$agent, $job] = $this->makeAgentAndJob();
        $this->registerHandler('TM', fn () => throw new RuntimeException('boom'));

        app(WorkerRunner::class)->run($agent, once: true);

        $fresh = $job->fresh();
        $this->assertSame('RETRY', $fresh->status->value);
        $this->assertSame('UNEXPECTED_EXCEPTION', $fresh->fail_reason_code);
    }

    public function test_retry_exhausted_failure_goes_failed(): void
    {
        [$agent, $job] = $this->makeAgentAndJob(['retry_count' => 3, 'max_retry' => 3]);
        $this->registerHandler('TM', fn () => JobResult::failure(
            FailureType::Transient, 'NETWORK_ERROR', 'still failing'
        ));

        app(WorkerRunner::class)->run($agent, once: true);

        $this->assertSame('FAILED', $job->fresh()->status->value);
    }

    public function test_shutdown_requested_drains_without_new_claims(): void
    {
        [$agent, $job] = $this->makeAgentAndJob();
        $this->registerHandler('TM', fn () => JobResult::success());

        $runner = app(WorkerRunner::class);
        $runner->requestShutdown(); // SIGTERM 시뮬레이션

        $processed = $runner->run($agent, once: true);

        $this->assertSame(0, $processed);
        $this->assertSame('READY', $job->fresh()->status->value); // 신규 Claim 없음
        $this->assertSame('OFFLINE', $agent->fresh()->status->value);
    }

    public function test_disabled_worker_does_not_claim(): void
    {
        $agent = WorkflowWorkerAgent::factory()->create([
            'status' => 'DISABLED', 'supported_job_types' => ['TM'],
        ]);
        $job = WorkflowJob::factory()->ready()->create();
        $this->registerHandler('TM', fn () => JobResult::success());

        $processed = app(WorkerRunner::class)->run($agent, once: true);

        $this->assertSame(0, $processed);
        $this->assertSame('READY', $job->fresh()->status->value);
        $this->assertSame('DISABLED', $agent->fresh()->status->value); // markOffline은 DISABLED를 건드리지 않는다
    }

    public function test_no_handler_results_in_retryable_failure(): void
    {
        [$agent, $job] = $this->makeAgentAndJob();
        // 핸들러 미등록

        app(WorkerRunner::class)->run($agent, once: true);

        $fresh = $job->fresh();
        $this->assertSame('RETRY', $fresh->status->value);
        $this->assertSame('NO_HANDLER', $fresh->fail_reason_code);
    }
}
