<?php

namespace App\Enums;

/**
 * 콘텐츠 미디어 유형 — contents.media_type · workflow_templates.media_type.
 * COMMON은 여기에 포함하지 않는다(transcode_profiles.media_type 전용 — ProfileMediaType 참조).
 */
enum MediaType: string
{
    case Video = 'VIDEO';
    case Image = 'IMAGE';
    case Audio = 'AUDIO';
    case Doc = 'DOC';
}
