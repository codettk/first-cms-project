<?php

namespace Tests\Feature\Database;

use App\Enums\JobStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration Seed Spec §15 — 전이 seed ↔ enum 일치 · 템플릿 seed · 프로파일 seed 검증.
 */
class SeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** State Machine Spec §5 전이표 원본 — enum·seed 모두 이 기준과 일치해야 한다 */
    private const SPEC_TRANSITIONS = [
        ['WAITING', 'READY'], ['WAITING', 'SKIPPED'], ['WAITING', 'CANCELED'],
        ['READY', 'RUNNING'], ['READY', 'SKIPPED'], ['READY', 'CANCELED'],
        ['RUNNING', 'SUCCESS'], ['RUNNING', 'FAILED'], ['RUNNING', 'RETRY'],
        ['RUNNING', 'TIMEOUT'], ['RUNNING', 'CANCELED'],
        ['TIMEOUT', 'RETRY'], ['TIMEOUT', 'FAILED'], ['TIMEOUT', 'CANCELED'],
        ['RETRY', 'READY'], ['RETRY', 'FAILED'], ['RETRY', 'CANCELED'],
        ['FAILED', 'RETRY'],
    ];

    public function test_transition_seed_matches_spec_and_enum_map(): void
    {
        $specPairs = array_map(fn ($p) => "{$p[0]}>{$p[1]}", self::SPEC_TRANSITIONS);
        sort($specPairs);

        $seedPairs = DB::table('workflow_job_allowed_transitions')
            ->get()
            ->map(fn ($r) => "{$r->from_status}>{$r->to_status}")
            ->sort()->values()->all();

        $enumPairs = [];
        foreach (JobStatus::transitionMap() as $from => $targets) {
            foreach ($targets as $to) {
                $enumPairs[] = "{$from}>{$to}";
            }
        }
        sort($enumPairs);

        $this->assertSame($specPairs, $seedPairs, 'seed와 스펙 전이표 불일치');
        $this->assertSame($specPairs, $enumPairs, 'enum transitionMap과 스펙 전이표 불일치');
    }

    public function test_four_templates_are_seeded_with_expected_step_counts(): void
    {
        $counts = DB::table('workflow_templates as t')
            ->join('workflow_template_steps as s', 's.template_id', '=', 't.id')
            ->groupBy('t.code')
            ->selectRaw('t.code, count(*) as steps')
            ->pluck('steps', 'code');

        $this->assertSame([
            'AUDIO_INGEST' => 9,
            'DOCUMENT_INGEST' => 10,
            'IMAGE_INGEST' => 9,
            'VIDEO_INGEST' => 8,
        ], $counts->sortKeys()->map(fn ($v) => (int) $v)->all());
    }

    public function test_one_active_template_per_media_type(): void
    {
        $active = DB::table('workflow_templates')
            ->where('is_active', true)
            ->groupBy('media_type')
            ->selectRaw('media_type, count(*) as cnt')
            ->pluck('cnt', 'media_type');

        $this->assertCount(4, $active);
        foreach (['VIDEO', 'IMAGE', 'AUDIO', 'DOC'] as $type) {
            $this->assertEquals(1, $active[$type], "media_type {$type} must have exactly one active template");
        }
    }

    public function test_video_template_dependency_chain(): void
    {
        $steps = DB::table('workflow_templates as t')
            ->join('workflow_template_steps as s', 's.template_id', '=', 't.id')
            ->where('t.code', 'VIDEO_INGEST')
            ->orderBy('s.step_order')
            ->get(['s.job_type', 's.depends_on', 's.is_required']);

        $chain = $steps->pluck('job_type')->all();
        $this->assertSame(['TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'], $chain);

        $deps = $steps->keyBy('job_type')->map(fn ($s) => $s->depends_on ? json_decode($s->depends_on, true) : null);
        $this->assertNull($deps['TM']);
        $this->assertSame(['TM'], $deps['VERIFY']);
        $this->assertSame(['VERIFY'], $deps['MA']);
        $this->assertSame(['MA'], $deps['TC']);
        $this->assertSame(['TC'], $deps['CA']);
        $this->assertSame(['CA'], $deps['INDEX']);
        $this->assertSame(['INDEX'], $deps['PUBLISH']);
        $this->assertSame(['PUBLISH'], $deps['CLEANUP']);

        $this->assertTrue($steps->every(fn ($s) => $s->is_required), 'VIDEO_INGEST v1 steps are all required');
    }

    public function test_document_template_branches_after_ma(): void
    {
        // DOC 분기: VERIFY → MA(media_type 확정) → DOC_PREVIEW·TEXT_EXTRACT (Revision Checklist 항목 1)
        $deps = DB::table('workflow_templates as t')
            ->join('workflow_template_steps as s', 's.template_id', '=', 't.id')
            ->where('t.code', 'DOCUMENT_INGEST')
            ->pluck('s.depends_on', 's.job_type')
            ->map(fn ($d) => $d ? json_decode($d, true) : null);

        $this->assertSame(['MA'], $deps['DOC_PREVIEW']);
        $this->assertSame(['MA'], $deps['TEXT_EXTRACT']);
        $this->assertSame(['DOC_PREVIEW', 'TEXT_EXTRACT'], $deps['OCR']);
        $this->assertSame(['TEXT_EXTRACT', 'CA'], $deps['INDEX']);
    }

    public function test_twelve_transcode_profiles_are_seeded(): void
    {
        $this->assertSame(12, DB::table('transcode_profiles')->count());
    }

    public function test_common_media_type_is_used_only_by_shared_presets(): void
    {
        $commonCodes = DB::table('transcode_profiles')
            ->where('media_type', 'COMMON')
            ->orderBy('code')
            ->pluck('code')
            ->all();

        $this->assertSame(['CATALOG_DEFAULT', 'THUMBNAIL_DEFAULT'], $commonCodes);
    }

    public function test_only_720p_video_proxy_is_active_in_mvp(): void
    {
        $activeVideoProxies = DB::table('transcode_profiles')
            ->where('rendition_type', 'PROXY_VIDEO')
            ->where('is_active', true)
            ->pluck('code')
            ->all();

        $this->assertSame(['VIDEO_PROXY_720P'], $activeVideoProxies);
    }

    public function test_profiles_have_variant_keys_matching_spec(): void
    {
        $variants = DB::table('transcode_profiles')->pluck('variant_key', 'code');

        $this->assertSame('720p', $variants['VIDEO_PROXY_720P']);
        $this->assertSame('hls_720p', $variants['VIDEO_PROXY_HLS_720P']);
        $this->assertSame('thumb_480', $variants['THUMBNAIL_DEFAULT']);
        $this->assertSame('catalog_default', $variants['CATALOG_DEFAULT']);
        $this->assertSame('page_preview', $variants['DOC_PREVIEW_WEBP_144DPI']);
        $this->assertSame('wave_json', $variants['AUDIO_WAVEFORM_JSON']);
        $this->assertSame('ocr_default', $variants['DOC_OCR_KO_EN']);
    }
}
