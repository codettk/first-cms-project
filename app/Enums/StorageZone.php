<?php

namespace App\Enums;

/**
 * 스토리지 구역 7종 — DB Table Spec §4.3 · Migration Seed Spec §4 확정.
 * MASTER zone은 웹 접근 금지.
 */
enum StorageZone: string
{
    case Temp = 'TEMP';
    case Master = 'MASTER';
    case Proxy = 'PROXY';
    case Thumbnail = 'THUMBNAIL';
    case Catalog = 'CATALOG';
    case Document = 'DOCUMENT';
    case Archive = 'ARCHIVE';
}
