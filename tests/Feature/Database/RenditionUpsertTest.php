<?php

namespace Tests\Feature\Database;

use App\Models\Content;
use App\Models\MediaFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration Seed Spec §15 — rendition upsert: 동일 키 재INSERT가 ON CONFLICT UPDATE로 동작 (3종 키 각각).
 */
class RenditionUpsertTest extends TestCase
{
    use RefreshDatabase;

    private Content $content;

    private MediaFile $mediaFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->content = Content::factory()->create();
        $this->mediaFile = MediaFile::factory()->create(['content_id' => $this->content->id]);
    }

    public function test_single_key_upsert(): void
    {
        $this->upsertSingle('proxy/a_720p.mp4');
        $this->upsertSingle('proxy/b_720p.mp4'); // 동일 키 재실행 — 덮어쓰기

        $rows = DB::table('media_renditions')
            ->where('content_id', $this->content->id)
            ->where('rendition_type', 'PROXY_VIDEO')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('proxy/b_720p.mp4', $rows[0]->path);
    }

    public function test_page_key_upsert(): void
    {
        $this->upsertPage(1, 'doc/p1_a.webp');
        $this->upsertPage(2, 'doc/p2.webp');
        $this->upsertPage(1, 'doc/p1_b.webp'); // page 1만 덮어쓰기

        $rows = DB::table('media_renditions')
            ->where('content_id', $this->content->id)
            ->where('rendition_type', 'PAGE_PREVIEW')
            ->orderBy('page_no')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('doc/p1_b.webp', $rows[0]->path);
        $this->assertSame('doc/p2.webp', $rows[1]->path);
    }

    public function test_timecode_key_upsert(): void
    {
        $this->upsertTimecode(5000, 'catalog/t5_a.webp');
        $this->upsertTimecode(10000, 'catalog/t10.webp');
        $this->upsertTimecode(5000, 'catalog/t5_b.webp'); // timecode 5000만 덮어쓰기

        $rows = DB::table('media_renditions')
            ->where('content_id', $this->content->id)
            ->where('rendition_type', 'CATALOG')
            ->orderBy('timecode_ms')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame('catalog/t5_b.webp', $rows[0]->path);
        $this->assertSame('catalog/t10.webp', $rows[1]->path);
    }

    public function test_different_variant_keys_coexist(): void
    {
        // 다중 화질 확장 — 스키마 변경 없이 720p/480p 행 공존 (Transcode Profile Spec §10)
        $this->upsertSingle('proxy/a_720p.mp4', '720p');
        $this->upsertSingle('proxy/a_480p.mp4', '480p');

        $count = DB::table('media_renditions')
            ->where('content_id', $this->content->id)
            ->where('rendition_type', 'PROXY_VIDEO')
            ->count();

        $this->assertSame(2, $count);
    }

    private function upsertSingle(string $path, string $variantKey = '720p'): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO media_renditions
                (content_id, media_file_id, rendition_type, storage_zone, variant_key, path)
            VALUES (?, ?, 'PROXY_VIDEO', 'PROXY', ?, ?)
            ON CONFLICT (content_id, rendition_type, variant_key)
                WHERE page_no IS NULL AND timecode_ms IS NULL
                DO UPDATE SET path = EXCLUDED.path
        SQL, [$this->content->id, $this->mediaFile->id, $variantKey, $path]);
    }

    private function upsertPage(int $pageNo, string $path): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO media_renditions
                (content_id, media_file_id, rendition_type, storage_zone, variant_key, path, page_no)
            VALUES (?, ?, 'PAGE_PREVIEW', 'DOCUMENT', 'page_preview', ?, ?)
            ON CONFLICT (content_id, rendition_type, variant_key, page_no)
                WHERE page_no IS NOT NULL
                DO UPDATE SET path = EXCLUDED.path
        SQL, [$this->content->id, $this->mediaFile->id, $path, $pageNo]);
    }

    private function upsertTimecode(int $timecodeMs, string $path): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO media_renditions
                (content_id, media_file_id, rendition_type, storage_zone, variant_key, path, timecode_ms)
            VALUES (?, ?, 'CATALOG', 'CATALOG', 'catalog_default', ?, ?)
            ON CONFLICT (content_id, rendition_type, variant_key, timecode_ms)
                WHERE timecode_ms IS NOT NULL
                DO UPDATE SET path = EXCLUDED.path
        SQL, [$this->content->id, $this->mediaFile->id, $path, $timecodeMs]);
    }
}
