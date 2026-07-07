<?php

namespace App\Exceptions;

/**
 * 허용 전이표에 없는 상태 전이 시도 또는 CAS 충돌 —
 * Admin API에서 409 INVALID_STATE_TRANSITION + current_state로 매핑된다 (Controller Service Spec §13).
 */
class InvalidStateTransitionException extends WorkflowException
{
    /**
     * @param list<string> $allowedActions
     */
    public function __construct(
        public readonly string $target,
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $currentState = null,
        public readonly array $allowedActions = [],
    ) {
        parent::__construct("invalid {$target} transition: {$from} -> {$to}");
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'INVALID_STATE_TRANSITION';
    }

    public function errorContext(): array
    {
        return array_filter([
            'current_state' => $this->currentState ?? $this->from,
            'allowed_actions' => $this->allowedActions,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
