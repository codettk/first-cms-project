<?php

namespace App\Services\Workflow\StateMachine;

use App\Enums\InstanceStatus;

/**
 * workflow_instances.status 전이 — State Machine Spec §4.
 * 종결 상태에서 RUNNING 복귀 금지 — 재처리는 항상 새 인스턴스 생성.
 */
class WorkflowInstanceStateMachine extends AbstractStateMachine
{
    protected function transitions(): array
    {
        return InstanceStatus::transitionMap();
    }

    protected function targetName(): string
    {
        return 'instance';
    }
}
