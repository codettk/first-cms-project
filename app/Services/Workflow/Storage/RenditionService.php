<?php

namespace App\Services\Workflow\Storage;

use App\Models\MediaRendition;
use Illuminate\Support\Facades\DB;

/**
 * media_renditions upsert — Worker Agent Spec §12 · Migration Seed Spec §7.
 * 재실행은 삭제가 아닌 upsert — variant_key 포함 unique 3종 키를 사용한다:
 *   단건  (content_id, rendition_type, variant_key) WHERE page_no IS NULL AND timecode_ms IS NULL
 *   페이지 (content_id, rendition_type, variant_key, page_no) WHERE page_no IS NOT NULL
 *   타임코드 (content_id, rendition_type, variant_key, timecode_ms) WHERE timecode_ms IS NOT NULL
 */
class RenditionService
{
    private const array UPDATABLE = [
        'media_file_id', 'storage_zone', 'path', 'file_size', 'checksum',
        'mime_type', 'width', 'height', 'duration_ms', 'profile_id', 'metadata',
    ];

    /**
     * @param array<string, mixed> $spec content_id·media_file_id·rendition_type·storage_zone·path 필수,
     *                                   variant_key 기본 'default', page_no/timecode_ms로 키 유형 결정
     */
    public function upsert(array $spec): MediaRendition
    {
        $spec['variant_key'] ??= 'default';

        if (isset($spec['metadata']) && is_array($spec['metadata'])) {
            $spec['metadata'] = json_encode($spec['metadata']);
        }

        [$conflictColumns, $conflictWhere] = $this->conflictTarget($spec);

        $columns = array_keys($spec);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $updates = implode(', ', array_map(
            fn (string $col) => "{$col} = EXCLUDED.{$col}",
            array_values(array_intersect(self::UPDATABLE, $columns)),
        ));

        DB::statement(
            'INSERT INTO media_renditions ('.implode(', ', $columns).", created_at)
             VALUES ({$placeholders}, now())
             ON CONFLICT (".implode(', ', $conflictColumns).") {$conflictWhere}
             DO UPDATE SET {$updates}",
            array_values($spec)
        );

        return MediaRendition::query()
            ->where('content_id', $spec['content_id'])
            ->where('rendition_type', $spec['rendition_type'])
            ->where('variant_key', $spec['variant_key'])
            ->when(isset($spec['page_no']), fn ($q) => $q->where('page_no', $spec['page_no']))
            ->when(isset($spec['timecode_ms']), fn ($q) => $q->where('timecode_ms', $spec['timecode_ms']))
            ->when(! isset($spec['page_no']) && ! isset($spec['timecode_ms']),
                fn ($q) => $q->whereNull('page_no')->whereNull('timecode_ms'))
            ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{0: list<string>, 1: string}
     */
    private function conflictTarget(array $spec): array
    {
        if (isset($spec['page_no'])) {
            return [
                ['content_id', 'rendition_type', 'variant_key', 'page_no'],
                'WHERE page_no IS NOT NULL',
            ];
        }

        if (isset($spec['timecode_ms'])) {
            return [
                ['content_id', 'rendition_type', 'variant_key', 'timecode_ms'],
                'WHERE timecode_ms IS NOT NULL',
            ];
        }

        return [
            ['content_id', 'rendition_type', 'variant_key'],
            'WHERE page_no IS NULL AND timecode_ms IS NULL',
        ];
    }
}
