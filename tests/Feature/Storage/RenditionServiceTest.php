<?php

namespace Tests\Feature\Storage;

use App\Models\Content;
use App\Models\MediaFile;
use App\Services\Workflow\Storage\RenditionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Worker Agent Spec §12 — variant_key 포함 unique 3종 키 upsert.
 */
class RenditionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RenditionService $renditions;

    private Content $content;

    private MediaFile $mediaFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->renditions = app(RenditionService::class);
        $this->content = Content::factory()->processing()->create();
        $this->mediaFile = MediaFile::factory()->for($this->content)->create();
    }

    /** @return array<string, mixed> */
    private function spec(array $overrides = []): array
    {
        return [
            'content_id' => $this->content->id,
            'media_file_id' => $this->mediaFile->id,
            'rendition_type' => 'PROXY_VIDEO',
            'storage_zone' => 'PROXY',
            'variant_key' => '720p',
            'path' => 'proxy/2026/07/'.$this->content->id.'_720p.mp4',
            'file_size' => 1000,
            'checksum' => str_repeat('a', 64),
            ...$overrides,
        ];
    }

    public function test_single_key_upsert_updates_instead_of_duplicating(): void
    {
        $first = $this->renditions->upsert($this->spec());
        $second = $this->renditions->upsert($this->spec([
            'file_size' => 2000, 'checksum' => str_repeat('b', 64),
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2000, $second->file_size);
        $this->assertSame(1, $this->content->renditions()->where('rendition_type', 'PROXY_VIDEO')->count());
    }

    public function test_different_variant_keys_create_separate_rows(): void
    {
        $this->renditions->upsert($this->spec());
        $this->renditions->upsert($this->spec([
            'variant_key' => '480p',
            'path' => 'proxy/2026/07/'.$this->content->id.'_480p.mp4',
        ]));

        $this->assertSame(2, $this->content->renditions()->where('rendition_type', 'PROXY_VIDEO')->count());
    }

    public function test_timecode_key_upsert(): void
    {
        $catalog = fn (int $ms, string $suffix = '') => $this->spec([
            'rendition_type' => 'CATALOG', 'storage_zone' => 'CATALOG',
            'variant_key' => 'catalog_default', 'timecode_ms' => $ms,
            'path' => "catalog/2026/07/{$this->content->id}_{$ms}{$suffix}.webp",
        ]);

        $this->renditions->upsert($catalog(5000));
        $this->renditions->upsert($catalog(10000));
        $updated = $this->renditions->upsert($catalog(5000, '_v2')); // 동일 타임코드 재실행

        $this->assertSame(2, $this->content->renditions()->where('rendition_type', 'CATALOG')->count());
        $this->assertStringContainsString('_v2', $updated->path);
    }

    public function test_page_key_upsert(): void
    {
        $page = fn (int $no) => $this->spec([
            'rendition_type' => 'PAGE_PREVIEW', 'storage_zone' => 'DOCUMENT',
            'variant_key' => 'doc_preview_default', 'page_no' => $no,
            'path' => "doc/2026/07/{$this->content->id}_p{$no}.webp",
        ]);

        $this->renditions->upsert($page(1));
        $this->renditions->upsert($page(2));
        $this->renditions->upsert($page(1));

        $this->assertSame(2, $this->content->renditions()->where('rendition_type', 'PAGE_PREVIEW')->count());
    }

    public function test_metadata_is_stored_as_jsonb(): void
    {
        $rendition = $this->renditions->upsert($this->spec([
            'metadata' => ['source_duration_ms' => 61500],
        ]));

        $this->assertSame(['source_duration_ms' => 61500], $rendition->metadata);
    }
}
