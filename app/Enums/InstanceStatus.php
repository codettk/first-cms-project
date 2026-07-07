<?php

namespace App\Enums;

enum InstanceStatus: string
{
    case Running = 'RUNNING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case Canceled = 'CANCELED';

    /**
     * 허용 전이표 — State Machine Spec §4. 종결 상태에서 RUNNING 복귀 금지.
     *
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Running->value => [self::Success->value, self::Failed->value, self::Canceled->value],
            self::Success->value => [],
            self::Failed->value => [],
            self::Canceled->value => [],
        ];
    }

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
