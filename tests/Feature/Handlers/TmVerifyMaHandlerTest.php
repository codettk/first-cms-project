<?php

namespace Tests\Feature\Handlers;

use App\Enums\JobType;
use App\Enums\StorageZone;
use App\Models\MediaRendition;
use App\Services\Workflow\Worker\Handlers\MediaAnalyzeJobHandler;
use App\Services\Workflow\Worker\Handlers\TmJobHandler;
use App\Services\Workflow\Worker\Handlers\VerifyJobHandler;
use Illuminate\Support\Facades\DB;

/**
 * Worker Agent Spec §9 — TM(TEMP→MASTER)·VERIFY(무결성)·MA(media_info) 핸들러.
 */
class TmVerifyMaHandlerTest extends HandlerTestCase
{
    private const string UPLOAD_BYTES = 'fake-master-video-bytes';

    /** 업로드 완료 상태의 TM job 픽스처 */
    private function makeTmFixture(): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Tm, mediaFileAttributes: [
            'temp_path' => 'temp/upload.mov',
            'file_size' => strlen(self::UPLOAD_BYTES),
            'checksum' => hash('sha256', self::UPLOAD_BYTES),
        ]);
        $this->putTempUpload('temp/upload.mov', self::UPLOAD_BYTES);

        return [$job, $content, $mediaFile];
    }

    public function test_tm_moves_temp_to_master_and_creates_master_rendition(): void
    {
        [$job, $content] = $this->makeTmFixture();

        $result = app(TmJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);

        $master = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'MASTER')->first();
        $this->assertNotNull($master);
        $this->assertSame(hash('sha256', self::UPLOAD_BYTES), $master->checksum);
        $this->assertStringStartsWith('master/', $master->path);
        $this->assertFileExists($this->storage->absolutePath(StorageZone::Master, $master->path));
    }

    public function test_tm_fails_permanently_when_temp_file_missing(): void
    {
        [$job] = $this->makeRunningJob(JobType::Tm, mediaFileAttributes: [
            'temp_path' => 'temp/nowhere.mov',
        ]);

        $result = app(TmJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('TEMP_FILE_MISSING', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    public function test_tm_detects_size_mismatch(): void
    {
        [$job] = $this->makeRunningJob(JobType::Tm, mediaFileAttributes: [
            'temp_path' => 'temp/upload.mov',
            'file_size' => 999_999,
            'checksum' => hash('sha256', self::UPLOAD_BYTES),
        ]);
        $this->putTempUpload('temp/upload.mov', self::UPLOAD_BYTES);

        $result = app(TmJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('SIZE_MISMATCH', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    public function test_tm_detects_checksum_mismatch_as_retryable_storage_error(): void
    {
        [$job] = $this->makeRunningJob(JobType::Tm, mediaFileAttributes: [
            'temp_path' => 'temp/upload.mov',
            'file_size' => strlen(self::UPLOAD_BYTES),
            'checksum' => str_repeat('0', 64),
        ]);
        $this->putTempUpload('temp/upload.mov', self::UPLOAD_BYTES);

        $result = app(TmJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('CHECKSUM_MISMATCH', $result->failReasonCode);
        $this->assertTrue($result->isRetryable());
    }

    /** VERIFY용 MASTER rendition + 파일 픽스처 */
    private function makeMasterFixture(string $bytes, array $jobType = []): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Verify);
        $path = "master/2026/07/{$content->id}_test.bin";
        $this->putZoneFile(StorageZone::Master, $path, $bytes);

        MediaRendition::create([
            'content_id' => $content->id,
            'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER',
            'storage_zone' => 'MASTER',
            'path' => $path,
            'file_size' => strlen($bytes),
            'checksum' => hash('sha256', $bytes),
        ]);

        return [$job, $content, $mediaFile];
    }

    public function test_verify_passes_for_intact_allowed_file(): void
    {
        // 최소 PNG — fileinfo가 image/png로 판정 (이미지는 ffprobe 생략 경로)
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        [$job, , $mediaFile] = $this->makeMasterFixture($png);

        $result = app(VerifyJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertSame('image/png', $result->resultData['detected_mime']);
        $this->assertSame('image/png', $mediaFile->fresh()->detected_mime);
    }

    public function test_verify_detects_corruption_via_checksum(): void
    {
        [$job, $content] = $this->makeMasterFixture('original-bytes');

        // 저장 후 파일이 변조된 상황
        $master = MediaRendition::where('content_id', $content->id)->firstOrFail();
        file_put_contents($this->storage->absolutePath(StorageZone::Master, $master->path), 'tampered!');

        $result = app(VerifyJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('PERMANENT_CORRUPT', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    public function test_verify_rejects_unsupported_format(): void
    {
        // MZ 헤더 — application/x-dosexec 류로 판정, 허용 목록 밖
        [$job] = $this->makeMasterFixture("MZ\x90\x00\x03\x00\x00\x00\x04\x00");

        $result = app(VerifyJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('UNSUPPORTED_FORMAT', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    /** MA용 MASTER 픽스처 (probe는 FakeToolRunner가 응답) */
    private function makeMaFixture(): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Ma);
        $path = "master/2026/07/{$content->id}_test.mov";
        $this->putZoneFile(StorageZone::Master, $path, 'video-bytes');

        MediaRendition::create([
            'content_id' => $content->id,
            'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER',
            'storage_zone' => 'MASTER',
            'path' => $path,
        ]);

        return [$job, $content, $mediaFile];
    }

    public function test_ma_confirms_video_type_and_stores_media_info(): void
    {
        [$job, $content, $mediaFile] = $this->makeMaFixture();

        $this->tools->pushProbeJson([
            'streams' => [
                ['codec_type' => 'video', 'codec_name' => 'prores', 'width' => 1920, 'height' => 1080],
                ['codec_type' => 'audio', 'codec_name' => 'pcm_s16le'],
            ],
            'format' => ['duration' => '61.5'],
        ]);

        $result = app(MediaAnalyzeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertSame('VIDEO', $result->resultData['media_type']);
        $this->assertSame(61500, $result->resultData['duration_ms']);
        $this->assertSame('VIDEO', $content->fresh()->media_type->value);
        $this->assertSame('61.5', $mediaFile->fresh()->media_info['format']['duration']);
    }

    public function test_ma_fails_permanently_when_probe_fails(): void
    {
        [$job] = $this->makeMaFixture();
        $this->tools->pushFailure(1, 'moov atom not found');

        $result = app(MediaAnalyzeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('MEDIA_TYPE_UNDETERMINED', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    public function test_ma_detects_audio_only_media(): void
    {
        [$job, $content] = $this->makeMaFixture();

        $this->tools->pushProbeJson([
            'streams' => [['codec_type' => 'audio', 'codec_name' => 'mp3']],
            'format' => ['duration' => '180.0'],
        ]);

        $result = app(MediaAnalyzeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertSame('AUDIO', $content->fresh()->media_type->value);
        // DB에서도 확인 — COMMON은 contents.media_type에 존재할 수 없다
        $this->assertNotSame('COMMON', DB::table('contents')->where('id', $content->id)->value('media_type'));
    }
}
