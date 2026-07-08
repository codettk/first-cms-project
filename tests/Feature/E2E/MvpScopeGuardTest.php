<?php

namespace Tests\Feature\E2E;

use App\Services\Workflow\Worker\HandlerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 구현 범위 가드 — MVP Scope Git Strategy §2 · WBS §M6.
 * MVP 8종 + M6 확장(IMAGE_TC·AUDIO_TC)은 등록돼야 하고, 작업 정의가 미확정인
 * 유형(OCR·STT·AI_ANALYSIS·WAVEFORM·HLS)은 Handler가 없어야 한다.
 * DB enum/seed에 확장값이 존재하는 것은 허용된다.
 */
class MvpScopeGuardTest extends TestCase
{
    use RefreshDatabase;

    private const array IMPLEMENTED_HANDLER_TYPES = [
        'TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP',
        'IMAGE_TC', 'AUDIO_TC', 'DOC_PREVIEW', 'TEXT_EXTRACT',
    ];

    private const array EXCLUDED_TYPES = [
        'OCR', 'STT', 'AI_ANALYSIS', 'WAVEFORM',
    ];

    public function test_implemented_handlers_are_registered(): void
    {
        $registry = app(HandlerRegistry::class);

        foreach (self::IMPLEMENTED_HANDLER_TYPES as $type) {
            $this->assertNotNull($registry->resolve($type), "handler {$type} 누락");
        }
    }

    public function test_excluded_job_types_have_no_handlers(): void
    {
        $registry = app(HandlerRegistry::class);

        foreach (self::EXCLUDED_TYPES as $type) {
            $this->assertNull($registry->resolve($type), "미확정/보류 유형 {$type}의 Handler가 구현되어 있다");
        }
    }

    public function test_no_excluded_handler_classes_exist(): void
    {
        $handlerDir = app_path('Services/Workflow/Worker/Handlers');
        $files = array_map('basename', glob($handlerDir.'/*.php') ?: []);

        $forbidden = ['Ocr', 'Stt', 'AiAnalysis', 'Hls', 'Waveform'];

        foreach ($files as $file) {
            foreach ($forbidden as $prefix) {
                $this->assertStringNotContainsString($prefix, $file, "보류 Handler 파일 존재: {$file}");
            }
        }
    }

    public function test_only_720p_profile_is_active_in_video_proxies(): void
    {
        // 다중 화질(480p/360p)·HLS는 예비 정의만 — is_active=false 유지 (Transcode Profile Spec §14)
        $this->seed();

        $activeVideoProxies = \App\Models\TranscodeProfile::query()
            ->where('rendition_type', 'PROXY_VIDEO')
            ->where('is_active', true)
            ->pluck('code');

        $this->assertSame(['VIDEO_PROXY_720P'], $activeVideoProxies->all());
    }
}
