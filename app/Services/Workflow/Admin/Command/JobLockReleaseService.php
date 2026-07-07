<?php

namespace App\Services\Workflow\Admin\Command;

use App\Enums\JobStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\JobLockNotFoundException;
use App\Models\User;
use App\Models\WorkflowJob;
use App\Services\Workflow\Admin\AuditLogger;
use App\Services\Workflow\Admin\AvailableActionService;
use App\Services\Workflow\Admin\Data\CommandResult;
use App\Services\Workflow\StateMachine\JobStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Lock 강제 회수 (HIGH) — lock FOR UPDATE → DELETE → RUNNING→RETRY|FAILED CAS
 * — 단일 트랜잭션 (Controller Service Spec §10·§16). fencing이 늦은 결과를 폐기한다.
 */
class JobLockReleaseService
{
    public function __construct(
        private readonly JobStateMachine $sm,
        private readonly AuditLogger $audit,
        private readonly AvailableActionService $actions,
    ) {}

    public function execute(WorkflowJob $job, string $reason, string $targetStatus, User $actor): CommandResult
    {
        return DB::transaction(function () use ($job, $reason, $targetStatus, $actor) {
            $job = WorkflowJob::query()->lockForUpdate()->findOrFail($job->id);
            $before = $job->status->value;

            $lock = DB::table('workflow_job_locks')
                ->where('job_id', $job->id)
                ->lockForUpdate()
                ->first();

            if ($lock === null) {
                throw new JobLockNotFoundException($job->id);
            }

            DB::table('workflow_job_locks')->where('job_id', $job->id)->delete();

            $extra = $targetStatus === JobStatus::Retry->value
                ? ['next_attempt_at' => now(), 'fail_reason_code' => 'LEASE_EXPIRED']
                : ['finished_at' => now(), 'fail_reason_code' => 'LEASE_EXPIRED'];

            $result = $this->sm->transition(
                $job, $targetStatus, "admin:{$actor->id}", 'lock_released: '.$reason, $extra,
            );

            if (! $result->ok) {
                throw new InvalidStateTransitionException(
                    'job', $before, $targetStatus, currentState: $result->conflictCurrentStatus,
                );
            }

            $this->audit->log('job.release_lock', $job, $before, $targetStatus, [
                'locked_by_worker_id' => $lock->locked_by_worker_id,
                'target_status' => $targetStatus,
            ], $reason);

            return CommandResult::ok(
                ['job_id' => $job->id, 'status' => $targetStatus],
                $this->actions->forJob($job->refresh(), $actor),
            );
        });
    }
}
