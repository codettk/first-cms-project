<?php

namespace Tests\Feature\StateMachine;

use App\Enums\JobStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\StateMachine\JobStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * State Machine Spec §5·§16 — 허용/금지 전이 전수, CAS 충돌, fencing, history 원자성.
 */
class JobStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private JobStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // DB trigger가 workflow_job_allowed_transitions seed를 참조한다
        $this->machine = app(JobStateMachine::class);
    }

    public function test_all_allowed_transitions_succeed_and_write_history(): void
    {
        foreach (JobStatus::transitionMap() as $from => $targets) {
            foreach ($targets as $to) {
                $job = WorkflowJob::factory()->create(['status' => $from]);

                $result = DB::transaction(
                    fn () => $this->machine->transition($job, $to, 'system', 'test')
                );

                $this->assertTrue($result->ok, "{$from} -> {$to} 전이가 실패했다");
                $this->assertSame($to, $job->fresh()->status->value);
                $this->assertDatabaseHas('workflow_job_histories', [
                    'job_id' => $job->id,
                    'from_status' => $from,
                    'to_status' => $to,
                    'actor' => 'system',
                ]);
            }
        }
    }

    public function test_forbidden_transitions_throw_and_write_nothing(): void
    {
        $forbidden = [
            ['WAITING', 'RUNNING'],  // READY 우회 금지
            ['FAILED', 'SUCCESS'],   // 직접 전이 금지 — RETRY 경유
            ['SUCCESS', 'RUNNING'],  // 종결
            ['SKIPPED', 'READY'],    // 종결
            ['CANCELED', 'RUNNING'], // 종결
            ['RETRY', 'RUNNING'],    // READY 경유
        ];

        foreach ($forbidden as [$from, $to]) {
            $job = WorkflowJob::factory()->create(['status' => $from]);

            try {
                DB::transaction(fn () => $this->machine->transition($job, $to, 'system'));
                $this->fail("{$from} -> {$to}가 차단되지 않았다");
            } catch (InvalidStateTransitionException) {
                // expected
            }

            $this->assertSame($from, $job->fresh()->status->value);
            $this->assertDatabaseMissing('workflow_job_histories', ['job_id' => $job->id]);
        }
    }

    public function test_cas_conflict_returns_conflict_value_not_exception(): void
    {
        $job = WorkflowJob::factory()->ready()->create();

        // 다른 프로세스가 먼저 전이한 상황 재현 — 모델은 stale READY로 남는다
        DB::table('workflow_jobs')->where('id', $job->id)->update(['status' => 'CANCELED']);

        $result = DB::transaction(
            fn () => $this->machine->transition($job, 'RUNNING', 'worker:1')
        );

        $this->assertFalse($result->ok);
        $this->assertSame('CANCELED', $result->conflictCurrentStatus);
        $this->assertFalse($result->isAlreadyAt('RUNNING'));
        $this->assertDatabaseMissing('workflow_job_histories', ['job_id' => $job->id]);
    }

    public function test_conflict_at_target_status_is_idempotent_success(): void
    {
        $job = WorkflowJob::factory()->ready()->create();

        DB::table('workflow_jobs')->where('id', $job->id)->update(['status' => 'RUNNING']);

        $result = DB::transaction(
            fn () => $this->machine->transition($job, 'RUNNING', 'worker:1')
        );

        $this->assertFalse($result->ok);
        $this->assertTrue($result->isAlreadyAt('RUNNING'));
    }

    public function test_fencing_discards_result_from_non_owner_worker(): void
    {
        $owner = WorkflowWorkerAgent::factory()->create();
        $stranger = WorkflowWorkerAgent::factory()->create();
        $job = WorkflowJob::factory()->running()->create(['worker_id' => $owner->id]);

        $result = DB::transaction(
            fn () => $this->machine->transitionOwnedBy($job, 'SUCCESS', $stranger->id, "worker:{$stranger->id}")
        );

        $this->assertFalse($result->ok, '소유자가 아닌 Worker의 완료 기록은 폐기되어야 한다');
        $this->assertSame('RUNNING', $job->fresh()->status->value);

        $result = DB::transaction(
            fn () => $this->machine->transitionOwnedBy($job, 'SUCCESS', $owner->id, "worker:{$owner->id}")
        );

        $this->assertTrue($result->ok);
        $this->assertSame('SUCCESS', $job->fresh()->status->value);
    }

    public function test_transition_and_history_roll_back_together(): void
    {
        $job = WorkflowJob::factory()->ready()->create();

        try {
            DB::transaction(function () use ($job) {
                $this->machine->transition($job, 'RUNNING', 'worker:1');
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('READY', $job->fresh()->status->value);
        $this->assertDatabaseMissing('workflow_job_histories', ['job_id' => $job->id]);
    }

    public function test_extra_columns_update_atomically_with_transition(): void
    {
        $job = WorkflowJob::factory()->running()->create();

        DB::transaction(fn () => $this->machine->transition(
            $job, 'SUCCESS', 'worker:1', null, ['finished_at' => now()]
        ));

        $fresh = $job->fresh();
        $this->assertSame('SUCCESS', $fresh->status->value);
        $this->assertNotNull($fresh->finished_at);
    }

    public function test_record_creation_writes_null_from_status(): void
    {
        $job = WorkflowJob::factory()->create();

        $this->machine->recordCreation($job, 'scheduler');

        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $job->id,
            'from_status' => null,
            'to_status' => 'WAITING',
            'actor' => 'scheduler',
        ]);
    }
}
