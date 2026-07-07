<?php

namespace Tests\Feature\Queue;

use App\Models\Content;
use App\Models\WorkflowInstance;
use App\Models\WorkflowJob;
use App\Models\WorkflowJobLock;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\StateMachine\JobStateMachine;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Queue Worker Spec §8 — Scheduler 7단계 틱 + 취소 강제 (SM Spec §11) + ADR-0003 재시도 회계.
 */
class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowSchedulerService $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->scheduler = app(WorkflowSchedulerService::class);
    }

    /** VIDEO 템플릿 인스턴스 생성 후 [instance, content] 반환 */
    private function makeVideoInstance(): array
    {
        $content = Content::factory()->processing()->create();
        $template = WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();
        $instance = DB::transaction(
            fn () => app(WorkflowInstanceFactory::class)->createForContent($content, $template)
        );

        return [$instance, $content];
    }

    /** trigger 허용 경로를 따라 상태를 강제 설정 (테스트 픽스처 전용) */
    private function forceStatus(WorkflowJob $job, string ...$path): void
    {
        foreach ($path as $status) {
            DB::table('workflow_jobs')->where('id', $job->id)->update(['status' => $status]);
        }
    }

    private function jobOf(WorkflowInstance $instance, string $type): WorkflowJob
    {
        return $instance->jobs()->where('job_type', $type)->firstOrFail();
    }

    public function test_tick_promotes_only_dependency_satisfied_jobs(): void
    {
        [$instance] = $this->makeVideoInstance();

        $counts = $this->scheduler->tick();

        $this->assertSame(1, $counts['ready']); // TM만 선행 없음
        $this->assertSame('READY', $this->jobOf($instance, 'TM')->status->value);
        $this->assertSame('WAITING', $this->jobOf($instance, 'VERIFY')->status->value);
        $this->assertSame('WAITING', $this->jobOf($instance, 'PUBLISH')->status->value);
    }

    public function test_tick_promotes_next_job_after_predecessor_success(): void
    {
        [$instance] = $this->makeVideoInstance();
        $this->forceStatus($this->jobOf($instance, 'TM'), 'READY', 'RUNNING', 'SUCCESS');

        $this->scheduler->tick();

        $this->assertSame('READY', $this->jobOf($instance, 'VERIFY')->status->value);
        $this->assertSame('WAITING', $this->jobOf($instance, 'MA')->status->value);
    }

    public function test_retry_resume_increments_retry_count(): void
    {
        $job = WorkflowJob::factory()->create([
            'status' => 'RETRY', 'retry_count' => 1, 'max_retry' => 3,
            'next_attempt_at' => now()->subMinute(),
        ]);

        $this->scheduler->tick();

        $fresh = $job->fresh();
        $this->assertSame('READY', $fresh->status->value);
        $this->assertSame(2, $fresh->retry_count); // ADR-0003: 재개 시 증가
        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $job->id, 'to_status' => 'READY', 'note' => 'retry_resume',
        ]);
    }

    public function test_retry_exhausted_becomes_failed(): void
    {
        $job = WorkflowJob::factory()->create([
            'status' => 'RETRY', 'retry_count' => 3, 'max_retry' => 3,
            'next_attempt_at' => now()->subMinute(),
        ]);

        $this->scheduler->tick();

        $fresh = $job->fresh();
        $this->assertSame('FAILED', $fresh->status->value);
        $this->assertNotNull($fresh->finished_at);
        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $job->id, 'to_status' => 'FAILED', 'note' => 'retry_exhausted',
        ]);
    }

    public function test_retry_not_due_is_untouched(): void
    {
        $job = WorkflowJob::factory()->create([
            'status' => 'RETRY', 'retry_count' => 1, 'max_retry' => 3,
            'next_attempt_at' => now()->addMinutes(5),
        ]);

        $this->scheduler->tick();

        $this->assertSame('RETRY', $job->fresh()->status->value);
    }

    public function test_timeout_reclassifies_to_retry_with_backoff_and_reclaims_lock(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create();
        $job = WorkflowJob::factory()->create([
            'status' => 'RUNNING', 'worker_id' => $worker->id,
            'started_at' => now()->subMinutes(30), 'timeout_sec' => 60,
            'retry_count' => 0, 'max_retry' => 3,
        ]);
        WorkflowJobLock::create([
            'job_id' => $job->id, 'locked_by_worker_id' => $worker->id,
            'locked_until' => now()->addMinutes(5),
        ]);

        $this->scheduler->tick();

        $fresh = $job->fresh();
        $this->assertSame('RETRY', $fresh->status->value);
        $this->assertSame('TOOL_TIMEOUT', $fresh->fail_reason_code);
        $this->assertNotNull($fresh->next_attempt_at);
        $this->assertDatabaseMissing('workflow_job_locks', ['job_id' => $job->id]);
        // TIMEOUT 경유 이력 확인
        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $job->id, 'from_status' => 'RUNNING', 'to_status' => 'TIMEOUT',
        ]);
    }

    public function test_timeout_exhausted_becomes_failed(): void
    {
        $job = WorkflowJob::factory()->create([
            'status' => 'RUNNING', 'started_at' => now()->subMinutes(30),
            'timeout_sec' => 60, 'retry_count' => 3, 'max_retry' => 3,
        ]);

        $this->scheduler->tick();

        $this->assertSame('FAILED', $job->fresh()->status->value);
    }

    public function test_expired_lock_is_reclaimed_and_late_result_fenced(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create();
        $job = WorkflowJob::factory()->create([
            'status' => 'RUNNING', 'worker_id' => $worker->id,
            'started_at' => now(), 'timeout_sec' => 3600,
            'retry_count' => 0, 'max_retry' => 3,
        ]);
        WorkflowJobLock::create([
            'job_id' => $job->id, 'locked_by_worker_id' => $worker->id,
            'locked_until' => now()->subMinute(), // 만료
        ]);

        $this->scheduler->tick();

        $fresh = $job->fresh();
        $this->assertSame('RETRY', $fresh->status->value);
        $this->assertSame('LEASE_EXPIRED', $fresh->fail_reason_code);
        $this->assertDatabaseMissing('workflow_job_locks', ['job_id' => $job->id]);

        // 죽었다 살아난 Worker의 늦은 SUCCESS는 CAS(status=RUNNING)에서 폐기된다
        // ($job은 회수 사실을 모르는 stale 모델 — 죽었던 Worker의 시점 재현)
        $late = DB::transaction(fn () => app(JobStateMachine::class)
            ->transitionOwnedBy($job, 'SUCCESS', $worker->id, "worker:{$worker->id}"));
        $this->assertFalse($late->ok);
        $this->assertSame('RETRY', $late->conflictCurrentStatus);
        $this->assertSame('RETRY', $job->fresh()->status->value);
    }

    public function test_stale_worker_goes_offline_and_its_locks_are_reclaimed(): void
    {
        $worker = WorkflowWorkerAgent::factory()->create([
            'status' => 'BUSY', 'last_heartbeat_at' => now()->subMinutes(5),
        ]);
        $job = WorkflowJob::factory()->create([
            'status' => 'RUNNING', 'worker_id' => $worker->id,
            'started_at' => now(), 'timeout_sec' => 3600,
        ]);
        DB::table('workflow_worker_agents')->where('id', $worker->id)
            ->update(['current_job_id' => $job->id]);
        WorkflowJobLock::create([
            'job_id' => $job->id, 'locked_by_worker_id' => $worker->id,
            'locked_until' => now()->addMinutes(5), // lease는 남아있지만 heartbeat 미수신
        ]);

        $this->scheduler->tick();

        $freshWorker = $worker->fresh();
        $this->assertSame('OFFLINE', $freshWorker->status->value);
        $this->assertNull($freshWorker->current_job_id);
        $this->assertSame('RETRY', $job->fresh()->status->value);
        $this->assertDatabaseMissing('workflow_job_locks', ['job_id' => $job->id]);
    }

    public function test_required_failure_skips_downstream_and_fails_instance_and_content(): void
    {
        [$instance, $content] = $this->makeVideoInstance();

        foreach (['TM', 'VERIFY', 'MA'] as $type) {
            $this->forceStatus($this->jobOf($instance, $type), 'READY', 'RUNNING', 'SUCCESS');
        }
        $this->forceStatus($this->jobOf($instance, 'TC'), 'READY', 'RUNNING', 'FAILED');

        $this->scheduler->tick();

        foreach (['CA', 'INDEX', 'PUBLISH', 'CLEANUP'] as $type) {
            $job = $this->jobOf($instance, $type);
            $this->assertSame('SKIPPED', $job->status->value, "{$type}가 SKIPPED가 아니다");
            $this->assertDatabaseHas('workflow_job_histories', [
                'job_id' => $job->id, 'to_status' => 'SKIPPED', 'note' => 'SKIP_UPSTREAM_FAILED',
            ]);
        }

        $this->assertSame('FAILED', $instance->fresh()->status->value);
        $this->assertSame('FAILED', $content->fresh()->status->value);
    }

    public function test_canceled_job_skips_downstream(): void
    {
        [$instance] = $this->makeVideoInstance();

        foreach (['TM', 'VERIFY', 'MA'] as $type) {
            $this->forceStatus($this->jobOf($instance, $type), 'READY', 'RUNNING', 'SUCCESS');
        }
        $this->forceStatus($this->jobOf($instance, 'TC'), 'READY', 'CANCELED');

        $this->scheduler->tick();

        $this->assertSame('SKIPPED', $this->jobOf($instance, 'CA')->status->value);
        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $this->jobOf($instance, 'CA')->id,
            'to_status' => 'SKIPPED', 'note' => 'SKIP_UPSTREAM_CANCELED',
        ]);
        $this->assertSame('CANCELED', $instance->fresh()->status->value);
    }

    public function test_publish_gate_requires_all_required_jobs_success(): void
    {
        [$instance] = $this->makeVideoInstance();

        foreach (['TM', 'VERIFY', 'MA', 'TC', 'CA'] as $type) {
            $this->forceStatus($this->jobOf($instance, $type), 'READY', 'RUNNING', 'SUCCESS');
        }

        // INDEX 미완 — PUBLISH는 대기해야 한다
        $this->scheduler->tick();
        $this->assertSame('WAITING', $this->jobOf($instance, 'PUBLISH')->status->value);

        $this->forceStatus($this->jobOf($instance, 'INDEX'), 'READY', 'RUNNING', 'SUCCESS');

        $counts = $this->scheduler->tick();
        $this->assertGreaterThanOrEqual(1, $counts['publish']);
        $this->assertSame('READY', $this->jobOf($instance, 'PUBLISH')->status->value);
        $this->assertSame('WAITING', $this->jobOf($instance, 'CLEANUP')->status->value); // PUBLISH SUCCESS 후에만
    }

    public function test_unresponsive_cancel_is_forced_by_scheduler(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create();
        $job = WorkflowJob::factory()->create([
            'status' => 'RUNNING', 'worker_id' => $worker->id,
            'started_at' => now(), 'timeout_sec' => 3600,
            'cancel_requested_at' => now()->subMinute(),
        ]);
        WorkflowJobLock::create([
            'job_id' => $job->id, 'locked_by_worker_id' => $worker->id,
            'locked_until' => now()->addMinutes(5),
        ]);
        DB::table('workflow_job_progresses')->insert([
            'job_id' => $job->id, 'progress_percent' => 42, 'updated_at' => now(),
        ]);

        $this->scheduler->tick();

        $this->assertSame('CANCELED', $job->fresh()->status->value);
        $this->assertDatabaseMissing('workflow_job_locks', ['job_id' => $job->id]);
        $this->assertDatabaseMissing('workflow_job_progresses', ['job_id' => $job->id]);
    }
}
