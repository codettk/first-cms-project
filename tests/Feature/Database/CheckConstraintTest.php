<?php

namespace Tests\Feature\Database;

use App\Models\Content;
use App\Models\WorkflowJob;
use App\Models\WorkflowJobProgress;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration Seed Spec §15 — CHECK 제약: 비허용 status/priority/percent INSERT 시 예외.
 */
class CheckConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_content_status_is_rejected(): void
    {
        $content = Content::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('contents')->where('id', $content->id)->update(['status' => 'BOGUS']);
    }

    public function test_common_is_rejected_in_contents_media_type(): void
    {
        $content = Content::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('contents')->where('id', $content->id)->update(['media_type' => 'COMMON']);
    }

    public function test_common_is_accepted_in_transcode_profiles_media_type(): void
    {
        $id = DB::table('transcode_profiles')->insertGetId([
            'code' => 'TEST_COMMON_PROFILE',
            'name' => 'common test',
            'media_type' => 'COMMON',
            'rendition_type' => 'THUMBNAIL',
            'variant_key' => 'thumb_480',
            'output_format' => 'webp',
            'is_active' => true,
        ]);

        $this->assertGreaterThan(0, $id);
    }

    public function test_invalid_job_priority_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        WorkflowJob::factory()->create(['priority' => 2000]); // CHECK 0~1000
    }

    public function test_invalid_progress_percent_is_rejected(): void
    {
        $job = WorkflowJob::factory()->running()->create();

        $this->expectException(QueryException::class);
        WorkflowJobProgress::query()->create([
            'job_id' => $job->id,
            'progress_percent' => 150, // CHECK 0~100
        ]);
    }

    public function test_invalid_storage_zone_is_rejected(): void
    {
        $content = Content::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('media_renditions')->insert([
            'content_id' => $content->id,
            'media_file_id' => $content->mediaFiles()->create([
                'original_filename' => 'a.mov', 'ext' => 'mov',
                'file_size' => 1, 'checksum' => str_repeat('a', 64),
            ])->id,
            'rendition_type' => 'MASTER',
            'storage_zone' => 'BOGUS_ZONE',
            'variant_key' => 'default',
            'path' => 'x/y.mov',
        ]);
    }

    public function test_zero_file_size_is_rejected(): void
    {
        $content = Content::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('media_files')->insert([
            'content_id' => $content->id,
            'original_filename' => 'a.mov', 'ext' => 'mov',
            'file_size' => 0, // CHECK > 0
            'checksum' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
