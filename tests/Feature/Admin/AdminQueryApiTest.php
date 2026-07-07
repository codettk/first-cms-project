<?php

namespace Tests\Feature\Admin;

use App\Models\Content;
use App\Models\MediaFile;
use App\Models\MediaRendition;
use App\Models\WorkflowJob;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Support\Facades\DB;

/**
 * Admin Query API — 계약 형태 · available_actions 서버 계산 · Master 경로 마스킹 (Spec §9·§12).
 */
class AdminQueryApiTest extends AdminApiTestCase
{
    private function makePipelineContent(): Content
    {
        $content = Content::factory()->processing()->create(['title' => '테스트 영상']);
        MediaFile::factory()->for($content)->create();
        $template = WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();
        DB::transaction(fn () => app(WorkflowInstanceFactory::class)->createForContent($content, $template));

        return $content;
    }

    public function test_dashboard_returns_contract_shape(): void
    {
        $this->makePipelineContent();

        $this->actingAs($this->admin())
            ->getJson('/admin/workflows/dashboard?refresh=1')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => ['contents', 'jobs', 'workers', 'queue' => ['tc_queue_length', 'max_wait_sec'],
                    'metrics' => ['avg_processing_sec', 'p95_processing_sec', 'failure_rate', 'retry_rate', 'throughput_1h'],
                    'throughput_24h', 'banners'],
            ])
            ->assertJsonPath('data.jobs.WAITING', 8);
    }

    public function test_dashboard_banners_derive_from_failures_and_offline_workers(): void
    {
        WorkflowWorkerAgent::factory()->create(['status' => 'OFFLINE']);
        WorkflowJob::factory()->failed()->create(['is_required' => true]);

        $response = $this->actingAs($this->admin())
            ->getJson('/admin/workflows/dashboard?refresh=1')
            ->assertOk();

        $eventTypes = collect($response->json('data.banners'))->pluck('event_type');
        $this->assertContains('worker_offline', $eventTypes);
        $this->assertContains('required_job_failed', $eventTypes);
    }

    public function test_contents_list_includes_derived_fields_and_actions(): void
    {
        $content = $this->makePipelineContent();

        $response = $this->actingAs($this->admin())
            ->getJson('/admin/workflows/contents?status=PROCESSING')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'last_page']]);

        $item = collect($response->json('data'))->firstWhere('content_id', $content->id);
        $this->assertNotNull($item);
        $this->assertSame('PROCESSING', $item['content_status']);
        $this->assertSame(6, $item['progress']['total_required']); // PUBLISH·CLEANUP 제외
        $this->assertFalse($item['has_failed_job']);
        $this->assertContains('cancel', $item['available_actions']); // PROCESSING + workflow.cancel
        $this->assertNotContains('reprocess', $item['available_actions']);
    }

    public function test_content_detail_masks_master_path(): void
    {
        $content = $this->makePipelineContent();
        $mediaFile = $content->mediaFiles()->first();
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER',
            'path' => 'master/2026/07/1001_a1b2c3d4_src.mov', 'file_size' => 1000,
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson("/admin/workflows/contents/{$content->id}")
            ->assertOk();

        $master = $response->json('data.renditions.master');
        $this->assertTrue($master['exists']);
        $this->assertSame('master/2026/07/1001****.mov', $master['path_masked']);
        $this->assertArrayNotHasKey('path', $master);

        // 응답 전체에 마스킹 안 된 master 경로가 없어야 한다
        $this->assertStringNotContainsString('1001_a1b2c3d4_src.mov', $response->getContent());

        $this->assertCount(8, $response->json('data.job_pipeline'));
    }

    public function test_jobs_list_filters_and_actions(): void
    {
        $failed = WorkflowJob::factory()->failed()->create(['fail_reason_code' => 'FFMPEG_FAILED']);
        WorkflowJob::factory()->ready()->create();

        $response = $this->actingAs($this->admin())
            ->getJson('/admin/workflows/jobs?status=FAILED')
            ->assertOk();

        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($failed->id, $items[0]['job_id']);
        $this->assertSame('FFMPEG_FAILED', $items[0]['fail_reason_code']);
        $this->assertContains('retry', $items[0]['available_actions']);
        $this->assertNotContains('release_lock', $items[0]['available_actions']);
    }

    public function test_job_detail_masks_master_paths_in_payload_and_result(): void
    {
        $job = WorkflowJob::factory()->success()->create([
            'result' => ['master_path' => 'master/2026/07/55_deadbeef.mov', 'checksum' => 'x'],
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson("/admin/workflows/jobs/{$job->id}")
            ->assertOk();

        // 마스킹 규칙: 파일명 앞 4자만 유지 (Controller Service Spec §12)
        $this->assertSame('master/2026/07/55_d****.mov', $response->json('data.result.master_path'));
    }

    public function test_workers_list_shape(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create();

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/admin/workflows/workers')
            ->assertOk();

        $item = collect($response->json('data'))->firstWhere('worker_id', $worker->id);
        $this->assertSame('ONLINE', $item['status']);
        $this->assertIsInt($item['heartbeat_delay_sec']);
        $this->assertContains('disable', $item['available_actions']);
    }

    public function test_worker_actions_hidden_without_high_permission(): void
    {
        WorkflowWorkerAgent::factory()->online()->create();

        $response = $this->actingAs($this->admin()) // HIGH 없음
            ->getJson('/admin/workflows/workers')
            ->assertOk();

        $this->assertNotContains('disable', $response->json('data.0.available_actions'));
    }
}
