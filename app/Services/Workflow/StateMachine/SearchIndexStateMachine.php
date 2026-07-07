<?php

namespace App\Services\Workflow\StateMachine;

use App\Enums\IndexStatus;

/**
 * search_index_states.status 전이 — State Machine Spec §14 (순환, 종결 상태 없음).
 */
class SearchIndexStateMachine extends AbstractStateMachine
{
    protected function transitions(): array
    {
        return IndexStatus::transitionMap();
    }

    protected function targetName(): string
    {
        return 'search_index';
    }
}
