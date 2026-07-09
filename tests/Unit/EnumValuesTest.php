<?php

namespace Tests\Unit;

use App\Enums\ContentStatus;
use App\Enums\IndexStatus;
use App\Enums\InstanceStatus;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\LogLevel;
use App\Enums\MediaType;
use App\Enums\ProfileMediaType;
use App\Enums\RenditionType;
use App\Enums\StorageZone;
use App\Enums\WorkerStatus;
use PHPUnit\Framework\TestCase;

/**
 * WBS 2.1 — enum 값이 스펙(State Machine Spec §2 · DB Table Spec §5·§8 · Job Type Definition §2)과
 * 정확히 일치하는지 검증한다.
 */
class EnumValuesTest extends TestCase
{
    private static function values(string $enum): array
    {
        return array_map(fn ($case) => $case->value, $enum::cases());
    }

    public function test_content_status_values(): void
    {
        $this->assertSame(
            ['UPLOADING', 'REGISTERED', 'PROCESSING', 'READY', 'FAILED', 'ARCHIVED', 'DELETED'],
            self::values(ContentStatus::class)
        );
    }

    public function test_job_status_values(): void
    {
        $this->assertSame(
            ['WAITING', 'READY', 'RUNNING', 'SUCCESS', 'FAILED', 'RETRY', 'SKIPPED', 'CANCELED', 'TIMEOUT'],
            self::values(JobStatus::class)
        );
    }

    public function test_instance_status_values(): void
    {
        $this->assertSame(['RUNNING', 'SUCCESS', 'FAILED', 'CANCELED'], self::values(InstanceStatus::class));
    }

    public function test_worker_status_values(): void
    {
        $this->assertSame(['ONLINE', 'OFFLINE', 'BUSY', 'DISABLED', 'ERROR'], self::values(WorkerStatus::class));
    }

    public function test_index_status_values(): void
    {
        $this->assertSame(['PENDING', 'INDEXED', 'STALE', 'FAILED'], self::values(IndexStatus::class));
    }

    public function test_media_type_has_no_common(): void
    {
        $this->assertSame(['VIDEO', 'IMAGE', 'AUDIO', 'DOC'], self::values(MediaType::class));
        $this->assertNotContains('COMMON', self::values(MediaType::class));
    }

    public function test_profile_media_type_includes_common(): void
    {
        $this->assertSame(['VIDEO', 'IMAGE', 'AUDIO', 'DOC', 'COMMON'], self::values(ProfileMediaType::class));
    }

    public function test_job_type_values(): void
    {
        $this->assertSame(
            ['TM', 'VERIFY', 'MA', 'TC', 'IMAGE_TC', 'AUDIO_TC', 'CA', 'DOC_PREVIEW', 'OCR',
                'TEXT_EXTRACT', 'INDEX', 'PUBLISH', 'CLEANUP', 'WAVEFORM', 'STT', 'AI_ANALYSIS'],
            self::values(JobType::class)
        );
    }

    public function test_storage_zone_values(): void
    {
        $this->assertSame(
            ['TEMP', 'MASTER', 'PROXY', 'THUMBNAIL', 'CATALOG', 'DOCUMENT', 'ARCHIVE'],
            self::values(StorageZone::class)
        );
    }

    public function test_rendition_type_values(): void
    {
        $this->assertSame(
            ['MASTER', 'PROXY_VIDEO', 'PROXY_IMAGE', 'PROXY_AUDIO', 'THUMBNAIL', 'CATALOG',
                'PAGE_PREVIEW', 'DOCUMENT_PREVIEW', 'WAVEFORM', 'OCR_TEXT', 'EXTRACTED_TEXT'],
            self::values(RenditionType::class)
        );
    }

    public function test_log_level_values(): void
    {
        $this->assertSame(['DEBUG', 'INFO', 'WARN', 'ERROR'], self::values(LogLevel::class));
    }

    public function test_job_status_transition_map_matches_state_machine_spec(): void
    {
        // State Machine Spec §5 전이표 — 18쌍
        $expected = [
            'WAITING' => ['READY', 'SKIPPED', 'CANCELED'],
            'READY' => ['RUNNING', 'SKIPPED', 'CANCELED'],
            'RUNNING' => ['SUCCESS', 'FAILED', 'RETRY', 'TIMEOUT', 'CANCELED'],
            'TIMEOUT' => ['RETRY', 'FAILED', 'CANCELED'],
            'RETRY' => ['READY', 'FAILED', 'CANCELED'],
            'FAILED' => ['RETRY'],
            'SUCCESS' => [],
            'SKIPPED' => [],
            'CANCELED' => [],
        ];

        $this->assertSame($expected, JobStatus::transitionMap());
    }

    public function test_job_status_terminal_states(): void
    {
        $this->assertTrue(JobStatus::Success->isTerminal());
        $this->assertTrue(JobStatus::Skipped->isTerminal());
        $this->assertTrue(JobStatus::Canceled->isTerminal());
        $this->assertFalse(JobStatus::Failed->isTerminal()); // 준종결 — Admin RETRY 허용
        $this->assertFalse(JobStatus::Running->isTerminal());
    }
}
