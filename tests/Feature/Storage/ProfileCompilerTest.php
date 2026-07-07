<?php

namespace Tests\Feature\Storage;

use App\Exceptions\ProfileInactiveException;
use App\Services\Workflow\Storage\ProfileCompiler;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Transcode Profile Spec §15 — code 조회 · 비활성 거부 · params → ffmpeg 인자.
 */
class ProfileCompilerTest extends TestCase
{
    use RefreshDatabase;

    private ProfileCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->compiler = app(ProfileCompiler::class);
    }

    public function test_loads_active_profile_by_code(): void
    {
        $profile = $this->compiler->loadByCode('VIDEO_PROXY_720P');

        $this->assertSame('PROXY_VIDEO', $profile->rendition_type->value);
        $this->assertSame('720p', $profile->variant_key);
        $this->assertTrue($profile->is_active);
    }

    public function test_unknown_profile_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->compiler->loadByCode('NO_SUCH_PROFILE');
    }

    public function test_inactive_profile_is_refused(): void
    {
        // v1 seed는 VIDEO_PROXY_720P만 활성 — 480P는 예비 정의(is_active=false)
        $this->expectException(ProfileInactiveException::class);
        $this->compiler->loadByCode('VIDEO_PROXY_480P');
    }

    public function test_ffmpeg_video_args_compile_from_profile_params(): void
    {
        $profile = $this->compiler->loadByCode('VIDEO_PROXY_720P');

        $args = $this->compiler->ffmpegVideoArgs($profile, '/in/master.mov', '/out/proxy.mp4.tmp');

        $joined = implode(' ', $args);
        $this->assertStringContainsString('-c:v libx264', $joined);
        $this->assertStringContainsString('-crf 23', $joined);
        $this->assertStringContainsString('-preset medium', $joined);
        $this->assertStringContainsString('-maxrate 2500k', $joined);
        $this->assertStringContainsString("scale='min(1280,iw)':-2", $joined);
        $this->assertStringContainsString('-movflags +faststart', $joined);
        $this->assertStringContainsString('-progress pipe:1', $joined);
        $this->assertSame('/out/proxy.mp4.tmp', end($args));
        $this->assertSame('-y', $args[0]);
    }

    public function test_frame_args_for_thumbnail_profile(): void
    {
        $profile = $this->compiler->loadByCode('THUMBNAIL_DEFAULT');

        $args = $this->compiler->ffmpegFrameArgs($profile, '/in/proxy.mp4', '/out/thumb.webp', 5.0);

        $joined = implode(' ', $args);
        $this->assertStringContainsString('-ss 5', $joined);
        $this->assertStringContainsString('-frames:v 1', $joined);
        $this->assertSame('/out/thumb.webp', end($args));
    }
}
