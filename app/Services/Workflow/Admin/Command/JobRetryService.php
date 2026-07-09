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
 * Job 수동 재시도 — FAILED→RETRY CAS + retry_count 초기화(옵션) + 즉시 재개
 * (Controller Service Spec §17). 이력·로그는 보존 — 새 실행은 attempt_no로 구분.
 */
class JobRetryService
{
    public function __construct(
        private readonly JobStateMachine $sm,
        private readonly AuditLogger $audit,
        private readonly AvailableActionService $actions,
    ) {}

    public function execute(WorkflowJob $job, ?string $reason, bool $resetRetryCount, ?int $priority, User $actor): CommandResult
    {
        return DB::transaction(function () use ($job, $reason, $resetRetryCount, $priority, $actor) {
            $job = WorkflowJob::query()->lockForUpdate()->findOrFail($job->id);
            $before = $job->status->value;

            $result = $this->sm->transition($job, JobStatus::Retry->value, "admin:{$actor->id}", $reason);

            if (! $result->ok) {
                if ($result->isAlreadyAt(JobStatus::Retry->value)) { // 멱등 흡수
                    return CommandResult::ok(
                        ['job_id' => $job->id, 'status' => 'RETRY'],
                        $this->actions->forJob($job->refresh(), $actor),
                    );
                }

                throw new InvalidStateTransitionException(
                    'job', $before, 'RETRY', currentState: $result->conflictCurrentStatus,
                );
            }

            $job->update([ // 상태 외 필드 — trigger 통과 (Controller Service Spec §17)
                'retry_count' => $resetRetryCount ? 0 : $job->retry_count,
                'next_attempt_at' => now(),
                'priority' => $priority ?? $job->priority,
                'finished_at' => null,
            ]);

            $this->audit->log('job.retry', $job, $before, 'RETRY', [
                'reset' => $resetRetryCount, 'priority' => $priority,
            ], $reason);

            $fresh = $job->refresh();

            return CommandResult::ok([
                'job_id' => $job->id,
                'status' => 'RETRY',
                'retry_count' => $fresh->retry_count,
                'next_attempt_at' => $fresh->next_attempt_at?->toIso8601String(),
            ], $this->actions->forJob($fresh, $actor));
        });
    }
}
