<?php

namespace App\Services\Workflow\Admin\Command;

use App\Enums\JobStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\User;
use App\Models\WorkflowJob;
use App\Services\Workflow\Admin\AuditLogger;
use App\Services\Workflow\Admin\AvailableActionService;
use App\Services\Workflow\Admin\Data\CommandResult;
use App\Services\Workflow\StateMachine\JobStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Job 취소 — WAITING/READY/RETRY→CANCELED 즉시 · RUNNING→cancel_requested_at 마킹(202)
 * (Controller Service Spec §10 · SM Spec §11). 후속 SKIPPED 전파는 Scheduler 틱이 수행.
 */
class JobCancelService
{
    public function __construct(
        private readonly JobStateMachine $sm,
        private readonly AuditLogger $audit,
        private readonly AvailableActionService $actions,
    ) {}

    public function execute(WorkflowJob $job, string $reason, User $actor): CommandResult
    {
        return DB::transaction(function () use ($job, $reason, $actor) {
            $job = WorkflowJob::query()->lockForUpdate()->findOrFail($job->id);
            $before = $job->status->value;

            if ($job->status === JobStatus::Running) {
                // Worker가 heartbeat 주기에 감지 → cancel() → 전이. 30초 무반응 시 Scheduler 강제.
                $job->update(['cancel_requested_at' => now()]);

                $this->audit->log('job.cancel', $job, 'RUNNING', 'RUNNING', ['mode' => 'requested'], $reason);

                return CommandResult::ok(
                    ['job_id' => $job->id, 'status' => 'RUNNING', 'cancel_requested' => true],
                    $this->actions->forJob($job->refresh(), $actor),
                    httpStatus: 202,
                );
            }

            $result = $this->sm->transition(
                $job, JobStatus::Canceled->value, "admin:{$actor->id}", $reason,
                ['finished_at' => now()],
            );

            if (! $result->ok) {
                if ($result->isAlreadyAt(JobStatus::Canceled->value)) {
                    return CommandResult::ok(
                        ['job_id' => $job->id, 'status' => 'CANCELED'],
                        $this->actions->forJob($job->refresh(), $actor),
                    );
                }

                throw new InvalidStateTransitionException(
                    'job', $before, 'CANCELED', currentState: $result->conflictCurrentStatus,
                );
            }

            $this->audit->log('job.cancel', $job, $before, 'CANCELED', [], $reason);

            return CommandResult::ok(
                ['job_id' => $job->id, 'status' => 'CANCELED'],
                $this->actions->forJob($job->refresh(), $actor),
            );
        });
    }
}
