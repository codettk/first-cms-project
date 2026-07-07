<?php

namespace Tests\Feature\Handlers;

use App\Enums\JobType;
use App\Enums\StorageZone;
use App\Models\MediaRendition;
use App\Services\Workflow\Worker\Handlers\CatalogJobHandler;
use App\Services\Workflow\Worker\Handlers\VideoTranscodeJobHandler;
use Illuminate\Support\Facades\DB;

/**
 * Worker Agent Spec §9 — TC(프록시 생성·진행률)·CA(썸네일/카탈로그) 핸들러.
 */
class TcCaHandlerTest extends HandlerTestCase
{
    /** TC job + MASTER rendition + media_info(61.5s) 픽스처 */
    private function makeTcFixture(array $payload = ['profile' => 'VIDEO_PROXY_720P']): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Tc, [
            'payload' => $payload, 'timeout_sec' => 7200,
        ], [
            'media_info' => ['format' => ['duration' => '61.5']],
        ]);

        $path = "master/2026/07/{$content->id}_src.mov";
        $this->putZoneFile(StorageZone::Master, $path, 'source-video');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER', 'path' => $path,
        ]);

        return [$job, $content, $mediaFile];
    }

    public function test_tc_creates_proxy_rendition_with_progress(): void
    {
        [$job, $content] = $this->makeTcFixture();

        // ① ffmpeg — 출력 파일 생성 + 진행률 라인 방출 (out_time_ms는 마이크로초)
        $this->tools->pushWritesOutput('proxy-bytes', function (array $command, ?\Closure $onLine) {
            $onLine?->__invoke('out_time_ms=30750000'); // 30.75s / 61.5s = 50%
        });
        // ② 출력 검증 ffprobe — 원본 ±1s 이내
        $this->tools->pushProbeJson([
            'streams' => [['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1280, 'height' => 720]],
            'format' => ['duration' => '61.4'],
        ]);

        $result = app(VideoTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');

        $proxy = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'PROXY_VIDEO')->first();
        $this->assertNotNull($proxy);
        $this->assertSame('720p', $proxy->variant_key);
        $this->assertNotNull($proxy->profile_id);
        $this->assertSame(1280, $proxy->width);
        $this->assertSame(61400, (int) $proxy->duration_ms);
        $this->assertFileExists($this->storage->absolutePath(StorageZone::Proxy, $proxy->path));

        // 진행률은 workflow_job_progresses에만 — 50% 지점 보고 확인 (종결 전이라 행 존재)
        $this->assertDatabaseHas('workflow_job_progresses', ['job_id' => $job->id]);
        $this->assertEqualsWithDelta(100.0,
            (float) DB::table('workflow_job_progresses')->where('job_id', $job->id)->value('progress_percent'), 0.01);
    }

    public function test_tc_refuses_inactive_profile_without_retry(): void
    {
        [$job] = $this->makeTcFixture(['profile' => 'VIDEO_PROXY_480P']); // seed상 비활성

        $result = app(VideoTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('PROFILE_INACTIVE', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    public function test_tc_classifies_ffmpeg_failure_as_retryable_tool_error(): void
    {
        [$job] = $this->makeTcFixture();
        $this->tools->pushFailure(1, "Error while decoding stream\nConversion failed!");

        $result = app(VideoTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('FFMPEG_FAILED', $result->failReasonCode);
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('Conversion failed', $result->failMessage);
    }

    public function test_tc_rejects_output_with_duration_deviation(): void
    {
        [$job] = $this->makeTcFixture();
        $this->tools->pushWritesOutput();
        $this->tools->pushProbeJson([
            'streams' => [['codec_type' => 'video', 'codec_name' => 'h264']],
            'format' => ['duration' => '50.0'], // 원본 61.5s 대비 1s 초과 이탈
        ]);

        $result = app(VideoTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('FFMPEG_FAILED', $result->failReasonCode);
        $this->assertStringContainsString('deviates', $result->failMessage);
    }

    public function test_tc_classifies_timeout(): void
    {
        [$job] = $this->makeTcFixture();
        $this->tools->pushFailure(124, 'killed');

        $result = app(VideoTranscodeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('TOOL_TIMEOUT', $result->failReasonCode);
    }

    /** CA job + PROXY_VIDEO rendition 픽스처 */
    private function makeCaFixture(array $payload = ['timecodes' => [5000, 10000]]): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Ca, ['payload' => $payload]);

        $path = "proxy/2026/07/{$content->id}_VIDEO_PROXY_720P.mp4";
        $this->putZoneFile(StorageZone::Proxy, $path, 'proxy-video');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'PROXY_VIDEO', 'storage_zone' => 'PROXY',
            'variant_key' => '720p', 'path' => $path, 'duration_ms' => 61500,
        ]);

        return [$job, $content, $mediaFile];
    }

    public function test_ca_creates_thumbnail_and_catalog_renditions(): void
    {
        [$job, $content] = $this->makeCaFixture();

        $this->tools->pushWritesOutput('thumb'); // 대표 썸네일
        $this->tools->pushWritesOutput('cat-1'); // 5000ms
        $this->tools->pushWritesOutput('cat-2'); // 10000ms

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(2, $result->resultData['catalog_created']);
        $this->assertSame(0, $result->resultData['catalog_failed']);

        $this->assertSame(1, MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'THUMBNAIL')->count());
        $this->assertSame([5000, 10000], MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'CATALOG')->orderBy('timecode_ms')
            ->pluck('timecode_ms')->map(fn ($v) => (int) $v)->all());
    }

    public function test_ca_thumbnail_failure_fails_the_job(): void
    {
        [$job] = $this->makeCaFixture();
        $this->tools->pushFailure(1, 'cannot seek');

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('FFMPEG_FAILED', $result->failReasonCode);
    }

    public function test_ca_partial_catalog_failure_is_success_with_warning(): void
    {
        [$job, $content] = $this->makeCaFixture();

        $this->tools->pushWritesOutput('thumb');
        $this->tools->pushWritesOutput('cat-1');
        $this->tools->pushFailure(1, 'frame extract failed'); // 10000ms 실패

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->resultData['catalog_created']);
        $this->assertSame(1, $result->resultData['catalog_failed']);
        $this->assertDatabaseHas('workflow_job_logs', ['job_id' => $job->id, 'level' => 'WARN']);
    }

    public function test_ca_filters_timecodes_beyond_duration(): void
    {
        [$job] = $this->makeCaFixture(['timecodes' => [5000, 999000]]); // 999s > 61.5s

        $this->tools->pushWritesOutput('thumb');
        $this->tools->pushWritesOutput('cat-1');

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertSame(1, $result->resultData['catalog_created']);
        $this->assertCount(2, $this->tools->commands); // thumb + 유효 타임코드 1건만 실행
    }

    public function test_ca_requires_proxy_not_master(): void
    {
        [$job] = $this->makeRunningJob(JobType::Ca); // PROXY_VIDEO rendition 없음

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('STORAGE_IO', $result->failReasonCode);
    }
}
