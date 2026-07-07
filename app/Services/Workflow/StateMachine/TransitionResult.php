<?php

namespace App\Services\Workflow\StateMachine;

/**
 * 전이 결과 값 객체 — Controller Service Spec §7·§16.
 * conflict는 예외가 아닌 값: 호출부가 재조회 후 이미 목표 상태면 멱등 성공, 아니면 409로 변환한다.
 */
final readonly class TransitionResult
{
    private function __construct(
        public bool $ok,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $conflictCurrentStatus = null,
    ) {}

    public static function ok(string $from, string $to): self
    {
        return new self(true, $from, $to);
    }

    public static function conflict(string $currentStatus): self
    {
        return new self(false, conflictCurrentStatus: $currentStatus);
    }

    /** CAS 충돌이지만 이미 목표 상태에 도달해 있는 경우 — 멱등 성공 취급 판단용 */
    public function isAlreadyAt(string $to): bool
    {
        return ! $this->ok && $this->conflictCurrentStatus === $to;
    }
}
