<?php

namespace App\Services\Workflow\Admin\Command;

use App\Enums\WorkerStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\User;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Admin\AuditLogger;
use App\Services\Workflow\Admin\AvailableActionService;
use App\Services\Workflow\Admin\Data\CommandResult;
use App\Services\Workflow\StateMachine\WorkerStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Worker 조작 (HIGH — worker.manage) — disable(drain 기본)/enable/clear-error
 * (Controller Service Spec §10 · SM Spec §13). ERROR 해제는 운영자 확인 후에만(자동 복귀 금지).
 */
class WorkerCommandService
{
    public function __construct(
        private readonly WorkerStateMachine $sm,
        private readonly AuditLogger $audit,
        private readonly AvailableActionService $actions,
    ) {}

    public function disable(WorkflowWorkerAgent $worker, string $reason, bool $drain, User $actor): CommandResult
    {
        return $this->transition($worker, WorkerStatus::Disabled->value, 'worker.disable', $reason, $actor,
            afterTransition: function (WorkflowWorkerAgent $worker) use ($drain) {
                if (! $drain && $worker->current_job_id !== null) {
                    // drain=false: 진행 중 job에 취소 신호 (Worker가 감지 후 중단)
                    DB::table('workflow_jobs')->where('id', $worker->current_job_id)
                        ->update(['cancel_requested_at' => now()]);
                }
            });
    }

    public function enable(WorkflowWorkerAgent $worker, ?string $reason, User $actor): CommandResult
    {
        return $this->transition($worker, WorkerStatus::Online->value, 'worker.enable', $reason, $actor);
    }

    public function clearError(WorkflowWorkerAgent $worker, string $reason, User $actor): CommandResult
    {
        if ($worker->status !== WorkerStatus::Error) {
            throw new InvalidStateTransitionException(
                'worker', $worker->status->value, WorkerStatus::Online->value,
                currentState: $worker->status->value,
            );
        }

        return $this->transition($worker, WorkerStatus::Online->value, 'worker.clear_error', $reason, $actor);
    }

    private function transition(
        WorkflowWorkerAgent $worker,
        string $to,
        string $action,
        ?string $reason,
        User $actor,
        ?callable $afterTransition = null,
    ): CommandResult {
        return DB::transaction(function () use ($worker, $to, $action, $reason, $actor, $afterTransition) {
            $worker = WorkflowWorkerAgent::query()->lockForUpdate()->findOrFail($worker->id);
            $before = $worker->status->value;

            $result = $this->sm->transition($worker, $to, "admin:{$actor->id}", $reason);

            if (! $result->ok) {
                if ($result->isAlreadyAt($to)) {
                    return CommandResult::ok(
                        ['worker_id' => $worker->id, 'status' => $to],
                        $this->actions->forWorker($worker->refresh(), $actor),
                    );
                }

                throw new InvalidStateTransitionException(
                    'worker', $before, $to, currentState: $result->conflictCurrentStatus,
                );
            }

            if ($afterTransition !== null) {
                $afterTransition($worker->refresh());
            }

            $this->audit->log($action, $worker, $before, $to, [], $reason);

            return CommandResult::ok(
                ['worker_id' => $worker->id, 'status' => $to],
                $this->actions->forWorker($worker->refresh(), $actor),
            );
        });
    }
}
