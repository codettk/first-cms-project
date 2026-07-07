<?php

namespace App\Enums;

enum WorkerStatus: string
{
    case Online = 'ONLINE';
    case Offline = 'OFFLINE';
    case Busy = 'BUSY';
    case Disabled = 'DISABLED';
    case Error = 'ERROR';

    /**
     * 허용 전이표 — State Machine Spec §13 (순환 — 종결 상태 없음).
     *
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Offline->value => [self::Online->value],
            self::Online->value => [self::Busy->value, self::Offline->value, self::Disabled->value, self::Error->value],
            self::Busy->value => [self::Online->value, self::Offline->value, self::Disabled->value, self::Error->value],
            self::Disabled->value => [self::Online->value],
            self::Error->value => [self::Online->value],
        ];
    }
}
