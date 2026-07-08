<?php

namespace Tests\Feature\Handlers;

use App\Enums\JobType;
use App\Enums\StorageZone;
use App\Models\MediaRendition;
use App\Services\Workflow\Worker\Handlers\AudioTranscodeJobHandler;
use App\Services\Workflow\Worker\Handlers\CatalogJobHandler;
use App\Services\Workflow\Worker\Handlers\ImageTranscodeJobHandler;
use App\Services\Workflow\Worker\Handlers\MediaAnalyzeJobHandler;
use App\Services\Workflow\Worker\Tools\ToolResult;
use Illuminate\Support\Facades\DB;

/**
 * M6 확장 — IMAGE_TC(§3.5)·AUDIO_TC(§3.6)·CA 이미지 분기(§3.7)·MA 이미지 판정 핸들러.
 */
class ImageAudioHandlerTest extends HandlerTestCase
{
    /** vipsthumbnail 페이크 — 출력 경로는 -o 인자([Q=..] 스펙 제거) */
    private function pushVipsWritesOutput(string $contents = 'webp-bytes'): void
    {
        $this->tools->push(function (array $command) use ($contents) {
            $outIndex = array_search('-o', $command, true);
            $out = preg_replace('/\[[^\]]*\]$/', '', $command[$outIndex + 1]);
            file_put_contents($out, $contents);

            return new ToolResult(0, '', '');
        });
    }

    /** IMAGE_TC job + MASTER rendition 픽스처 */
    private function makeImageTcFixture(array $payload = ['profile' => 'IMAGE_PROXY_WEBP_2048']): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::ImageTc, [
            'payload' => $payload, 'timeout_sec' => 900,
        ]);

        $path = "master/2026/07/{$content->id}_src.tif";
        $this->putZoneFile(StorageZone::Master, $path, 'source-image');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER', 'path' => $path,
        ]);

        return [$job, $content, $mediaFile];
    }

    public function test_image_tc_creates_proxy_image_rendition(): void
    {
        [$job, $content] = $this->makeImageTcFixture();

        $this->pushVipsWritesOutput();
        // 출력 검증 ffprobe — 최대 변 2048 이내
        $this->tools->pushProbeJson([
            'streams' => [['codec_type' => 'video', 'codec_name' => 'webp', 'width' => 2048, 'height' => 1365]],
            'format' => ['format_name' => 'webp_pipe'],
        ]);

        $result = app(ImageTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');

        $proxy = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'PROXY_IMAGE')->first();
        $this->assertNotNull($proxy);
        $this->assertSame('default', $proxy->variant_key);
        $this->assertNotNull($proxy->profile_id);
        $this->assertSame(2048, $proxy->width);
        $this->assertSame(1365, $proxy->height);
        $this->assertSame('PROXY', $proxy->storage_zone->value);
        $this->assertFileExists($this->storage->absolutePath(StorageZone::Proxy, $proxy->path));

        // 프로파일 표준 인자 — Q82·strip·축소만·autorotate·sRGB (Transcode Profile Spec §12)
        $vipsCommand = $this->tools->commands[0];
        $this->assertStringContainsString('[Q=82,strip]', $vipsCommand[array_search('-o', $vipsCommand, true) + 1]);
        $this->assertContains('2048x2048>', $vipsCommand);
        $this->assertContains('--rotate', $vipsCommand);
        $this->assertContains('--eprofile', $vipsCommand);
    }

    public function test_image_tc_rejects_output_exceeding_max_side(): void
    {
        [$job] = $this->makeImageTcFixture();

        $this->pushVipsWritesOutput();
        $this->tools->pushProbeJson([
            'streams' => [['codec_type' => 'video', 'codec_name' => 'webp', 'width' => 4096, 'height' => 2730]],
            'format' => ['format_name' => 'webp_pipe'],
        ]);

        $result = app(ImageTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('VIPS_FAILED', $result->failReasonCode);
        $this->assertStringContainsString('exceeds max side', $result->failMessage);
    }

    public function test_image_tc_classifies_vips_failure_as_retryable_tool_error(): void
    {
        [$job] = $this->makeImageTcFixture();
        $this->tools->pushFailure(1, 'vips__file_read: unable to load source');

        $result = app(ImageTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('VIPS_FAILED', $result->failReasonCode);
        $this->assertTrue($result->isRetryable());
    }

    public function test_image_tc_refuses_inactive_profile_without_retry(): void
    {
        [$job] = $this->makeImageTcFixture(['profile' => 'VIDEO_PROXY_480P']); // seed상 비활성

        $result = app(ImageTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('PROFILE_INACTIVE', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    /** AUDIO_TC job + MASTER rendition + media_info(61.5s) 픽스처 */
    private function makeAudioTcFixture(array $payload = ['profile' => 'AUDIO_PROXY_AAC_128K']): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::AudioTc, [
            'payload' => $payload, 'timeout_sec' => 1800,
        ], [
            'media_info' => ['format' => ['duration' => '61.5']],
        ]);

        $path = "master/2026/07/{$content->id}_src.wav";
        $this->putZoneFile(StorageZone::Master, $path, 'source-audio');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER', 'path' => $path,
        ]);

        return [$job, $content, $mediaFile];
    }

    public function test_audio_tc_creates_proxy_audio_rendition(): void
    {
        [$job, $content] = $this->makeAudioTcFixture();

        $this->tools->pushWritesOutput('aac-bytes');
        // 출력 검증 ffprobe — 원본 ±0.5s 이내 (61.5s → 61.4s)
        $this->tools->pushProbeJson([
            'streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']],
            'format' => ['duration' => '61.4'],
        ]);

        $result = app(AudioTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');

        $proxy = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'PROXY_AUDIO')->first();
        $this->assertNotNull($proxy);
        $this->assertSame('default', $proxy->variant_key);
        $this->assertSame(61400, (int) $proxy->duration_ms);
        $this->assertFileExists($this->storage->absolutePath(StorageZone::Proxy, $proxy->path));

        // 프로파일 표준 인자 — AAC 128k·44.1kHz·2ch (Transcode Profile Spec §6)
        $ffmpegCommand = $this->tools->commands[0];
        $this->assertContains('-vn', $ffmpegCommand);
        $this->assertContains('128k', $ffmpegCommand);
        $this->assertContains('44100', $ffmpegCommand);
    }

    public function test_audio_tc_rejects_duration_deviation_beyond_half_second(): void
    {
        [$job] = $this->makeAudioTcFixture();

        $this->tools->pushWritesOutput();
        $this->tools->pushProbeJson([
            'streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']],
            'format' => ['duration' => '60.0'], // 원본 61.5s 대비 0.5s 초과 이탈
        ]);

        $result = app(AudioTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('FFMPEG_FAILED', $result->failReasonCode);
        $this->assertStringContainsString('deviates', $result->failMessage);
    }

    public function test_audio_tc_classifies_ffmpeg_failure_as_retryable_tool_error(): void
    {
        [$job] = $this->makeAudioTcFixture();
        $this->tools->pushFailure(1, 'Invalid data found when processing input');

        $result = app(AudioTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('FFMPEG_FAILED', $result->failReasonCode);
        $this->assertTrue($result->isRetryable());
    }

    public function test_ca_for_image_creates_thumbnail_only(): void
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Ca);
        DB::table('contents')->where('id', $content->id)->update(['media_type' => 'IMAGE']);

        $path = "proxy/2026/07/{$content->id}_IMAGE_PROXY_WEBP_2048.webp";
        $this->putZoneFile(StorageZone::Proxy, $path, 'proxy-image');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'PROXY_IMAGE', 'storage_zone' => 'PROXY',
            'variant_key' => 'default', 'path' => $path,
        ]);

        $this->pushVipsWritesOutput('thumb-bytes');

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(0, $result->resultData['catalog_created']);

        $thumb = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'THUMBNAIL')->first();
        $this->assertNotNull($thumb);
        $this->assertSame('thumb_480', $thumb->variant_key);
        $this->assertSame(0, MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'CATALOG')->count());

        // vipsthumbnail로 리사이즈 — 480 축소만 (IMAGE_THUMBNAIL_WEBP_480)
        $this->assertContains('480x480>', $this->tools->commands[0]);
    }

    public function test_ca_for_image_requires_proxy_image(): void
    {
        [$job, $content] = $this->makeRunningJob(JobType::Ca);
        DB::table('contents')->where('id', $content->id)->update(['media_type' => 'IMAGE']);

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('STORAGE_IO', $result->failReasonCode);
    }

    public function test_ma_detects_image_and_stores_resolution(): void
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Ma);

        $path = "master/2026/07/{$content->id}_src.png";
        $this->putZoneFile(StorageZone::Master, $path, 'png-bytes');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER', 'path' => $path,
        ]);

        // 정지 이미지 — ffprobe상 video 스트림 + 이미지 컨테이너 format
        $this->tools->pushProbeJson([
            'streams' => [['codec_type' => 'video', 'codec_name' => 'png', 'width' => 3000, 'height' => 2000]],
            'format' => ['format_name' => 'png_pipe'],
        ]);

        $result = app(MediaAnalyzeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame('IMAGE', $result->resultData['media_type']);
        $this->assertSame('IMAGE', $content->fresh()->media_type->value);
        $this->assertSame(3000, $result->resultData['width']);
    }
}
