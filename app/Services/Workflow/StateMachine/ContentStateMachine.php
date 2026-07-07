<?php

namespace App\Services\Workflow\StateMachine;

use App\Enums\ContentStatus;

/**
 * contents.status 전이 — State Machine Spec §3.
 * DELETED는 종결: 어떤 전이도 불가하며 job 생성도 INSERT trigger가 차단한다.
 */
class ContentStateMachine extends AbstractStateMachine
{
    protected function transitions(): array
    {
        return ContentStatus::transitionMap();
    }

    protected function targetName(): string
    {
        return 'content';
    }
}
