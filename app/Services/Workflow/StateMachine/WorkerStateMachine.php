<?php

namespace App\Services\Workflow\StateMachine;

use App\Enums\WorkerStatus;

/**
 * workflow_worker_agents.status 전이 — State Machine Spec §13.
 * ERROR → ONLINE은 Admin만 (자동 복귀 금지).
 */
class WorkerStateMachine extends AbstractStateMachine
{
    protected function transitions(): array
    {
        return WorkerStatus::transitionMap();
    }

    protected function targetName(): string
    {
        return 'worker';
    }
}
