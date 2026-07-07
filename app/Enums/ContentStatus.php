<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Uploading = 'UPLOADING';
    case Registered = 'REGISTERED';
    case Processing = 'PROCESSING';
    case Ready = 'READY';
    case Failed = 'FAILED';
    case Archived = 'ARCHIVED';
    case Deleted = 'DELETED';

    /**
     * 허용 전이표 — State Machine Spec §3.
     *
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Uploading->value => [self::Registered->value, self::Failed->value],
            self::Registered->value => [self::Processing->value],
            self::Processing->value => [self::Ready->value, self::Failed->value],
            self::Ready->value => [self::Processing->value, self::Archived->value, self::Deleted->value],
            self::Failed->value => [self::Processing->value, self::Deleted->value],
            self::Archived->value => [self::Ready->value, self::Processing->value, self::Deleted->value],
            self::Deleted->value => [],
        ];
    }

    public function isTerminal(): bool
    {
        return $this === self::Deleted;
    }
}
