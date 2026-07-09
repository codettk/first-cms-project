<?php

namespace Tests\Feature\Database;

use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\ProfileMediaType;
use App\Models\Content;
use App\Models\TranscodeProfile;
use App\Models\WorkflowJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Migration Seed Spec §15 — JSONB cast 왕복·enum cast 검증.
 */
class ModelCastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_jsonb_payload_round_trip(): void
    {
        $payload = [
            'profile' => 'VIDEO_PROXY_720P',
            'nested' => ['timecodes' => [5000, 10000, 20000], 'flag' => true],
        ];

        $job = WorkflowJob::factory()->create(['payload' => $payload]);

        // jsonb는 객체 키 순서를 보존하지 않으므로 순서 무관 비교
        $this->assertEquals($payload, $job->fresh()->payload);
    }

    public function test_status_and_type_enum_casts(): void
    {
        $job = WorkflowJob::factory()->ofType(JobType::Tc)->ready()->create();
        $fresh = $job->fresh();

        $this->assertSame(JobStatus::Ready, $fresh->status);
        $this->assertSame(JobType::Tc, $fresh->job_type);
    }

    public function test_content_enum_casts(): void
    {
        $content = Content::factory()->create();

        $this->assertSame(ContentStatus::Uploading, $content->fresh()->status);
        $this->assertNull($content->fresh()->media_type);
    }

    public function test_seeded_profile_params_cast(): void
    {
        $profile = TranscodeProfile::query()->where('code', 'VIDEO_PROXY_720P')->firstOrFail();

        $this->assertSame(ProfileMediaType::Video, $profile->media_type);
        $this->assertTrue($profile->is_active);
        $this->assertSame('2500k', $profile->params['maxrate']);
        $this->assertSame(30, $profile->params['fps_cap']);
        $this->assertSame(23, $profile->quality); // CRF
    }

    public function test_common_profile_cast(): void
    {
        $profile = TranscodeProfile::query()->where('code', 'THUMBNAIL_DEFAULT')->firstOrFail();

        $this->assertSame(ProfileMediaType::Common, $profile->media_type);
        $this->assertSame(5, $profile->params['video_seek_sec']);
    }
}
