<?php

namespace App\Enums;

enum IndexStatus: string
{
    case Pending = 'PENDING';
    case Indexed = 'INDEXED';
    case Stale = 'STALE';
    case Failed = 'FAILED';

    /**
     * 허용 전이표 — State Machine Spec §14 (순환 — 종결 상태 없음).
     *
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Pending->value => [self::Indexed->value, self::Failed->value],
            self::Indexed->value => [self::Stale->value],
            self::Stale->value => [self::Indexed->value, self::Failed->value],
            self::Failed->value => [self::Stale->value, self::Indexed->value],
        ];
    }
}
