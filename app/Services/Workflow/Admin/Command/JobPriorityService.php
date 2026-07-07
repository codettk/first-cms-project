<?php

namespace App\Services\Workflow\Admin\Command;

use App\Enums\JobStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\User;
use App\Models\WorkflowJob;
use App\Services\Workflow\Admin\AuditLogger;
use App\Services\Workflow\Admin\AvailableActionService;
use App\Services\Workflow\Admin\Data\CommandResult;
use Illuminate\Support\Facades\DB;

/**
 * 우선순위 변경 — 상태 전이가 아니다(history 없음) · WAITING/READY/RETRY만 허용
 * (Controller Service Spec §10 · §8 매트릭스).
 */
class JobPriorityService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AvailableActionService $actions,
    ) {}

    public function execute(WorkflowJob $job, int $priority, ?string $reason, User $actor): CommandResult
    {
        return DB::transaction(function () use ($job, $priority, $reason, $actor) {
            $job = WorkflowJob::query()->lockForUpdate()->findOrFail($job->id);

            if (! in_array($job->status, [JobStatus::Waiting, JobStatus::Ready, JobStatus::Retry], true)) {
                throw new InvalidStateTransitionException(
                    'job', $job->status->value, 'priority_update',
                    currentState: $job->status->value,
                    allowedActions: $this->actions->forJob($job, $actor),
                );
            }

            $before = $job->priority;
            $job->update(['priority' => $priority]);

            $this->audit->log('job.update_priority', $job, (string) $before, (string) $priority, [
                'priority' => $priority,
            ], $reason);

            return CommandResult::ok(
                ['job_id' => $job->id, 'priority' => $priority],
                $this->actions->forJob($job->refresh(), $actor),
            );
        });
    }
}
