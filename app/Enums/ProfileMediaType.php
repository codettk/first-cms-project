<?php

namespace App\Enums;

/**
 * transcode_profiles.media_type 전용 — Transcode Profile Spec §3.
 * COMMON은 여러 미디어 유형에서 재사용되는 공통 프리셋(THUMBNAIL_DEFAULT·CATALOG_DEFAULT)에만 사용한다.
 * contents.media_type과 OpenAPI 콘텐츠 MediaType에는 COMMON을 넣지 않는다.
 */
enum ProfileMediaType: string
{
    case Video = 'VIDEO';
    case Image = 'IMAGE';
    case Audio = 'AUDIO';
    case Doc = 'DOC';
    case Common = 'COMMON';
}
