<?php

namespace App\Enums;

enum JobStatus: string
{
    case Waiting = 'WAITING';
    case Ready = 'READY';
    case Running = 'RUNNING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case Retry = 'RETRY';
    case Skipped = 'SKIPPED';
    case Canceled = 'CANCELED';
    case Timeout = 'TIMEOUT';

    /**
     * 허용 전이표 — State Machine Spec §5·§16.
     * workflow_job_allowed_transitions seed와 단위 테스트로 일치를 검증한다.
     *
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Waiting->value => [self::Ready->value, self::Skipped->value, self::Canceled->value],
            self::Ready->value => [self::Running->value, self::Skipped->value, self::Canceled->value],
            self::Running->value => [self::Success->value, self::Failed->value, self::Retry->value, self::Timeout->value, self::Canceled->value],
            self::Timeout->value => [self::Retry->value, self::Failed->value, self::Canceled->value],
            self::Retry->value => [self::Ready->value, self::Failed->value, self::Canceled->value],
            self::Failed->value => [self::Retry->value],
            self::Success->value => [],
            self::Skipped->value => [],
            self::Canceled->value => [],
        ];
    }

    /** SUCCESS · SKIPPED · CANCELED — 종결 상태 (FAILED는 준종결) */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Success, self::Skipped, self::Canceled], true);
    }
}
