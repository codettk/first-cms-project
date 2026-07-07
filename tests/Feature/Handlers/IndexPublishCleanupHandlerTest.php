<?php

namespace Tests\Feature\Handlers;

use App\Enums\JobType;
use App\Enums\StorageZone;
use App\Models\MediaRendition;
use App\Models\SearchIndexState;
use App\Models\WorkflowJob;
use App\Services\Workflow\Search\SearchIndexClient;
use App\Services\Workflow\Worker\Handlers\CleanupJobHandler;
use App\Services\Workflow\Worker\Handlers\IndexJobHandler;
use App\Services\Workflow\Worker\Handlers\PublishJobHandler;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Worker Agent Spec §9 — INDEX(색인)·PUBLISH(§8 판정)·CLEANUP(정리) 핸들러.
 */
class IndexPublishCleanupHandlerTest extends HandlerTestCase
{
    public function test_index_upserts_document_and_marks_indexed(): void
    {
        [$job, $content] = $this->makeRunningJob(JobType::Index);

        $result = app(IndexJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertSame("content-{$content->id}", $result->resultData['index_doc_id']);

        $state = SearchIndexState::where('content_id', $content->id)->firstOrFail();
        $this->assertSame('INDEXED', $state->status->value);
        $this->assertSame(1, (int) $state->index_version);
        $this->assertNotNull($state->indexed_at);

        // 색인 후 조회 검증 — Mock 클라이언트(개발 테스트용)에서 문서 확인
        $this->assertNotNull(app(SearchIndexClient::class)->get("content-{$content->id}"));
    }

    public function test_index_engine_error_is_retryable_search_engine_failure(): void
    {
        [$job] = $this->makeRunningJob(JobType::Index);

        $this->app->instance(SearchIndexClient::class, new class implements SearchIndexClient
        {
            public function upsert(string $documentId, array $document): void
            {
                throw new RuntimeException('connection refused');
            }

            public function get(string $documentId): ?array
            {
                return null;
            }
        });

        $result = app(IndexJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('INDEX_UNAVAILABLE', $result->failReasonCode);
        $this->assertTrue($result->isRetryable());
    }

    public function test_index_rerun_is_idempotent_when_already_indexed(): void
    {
        [$job, $content] = $this->makeRunningJob(JobType::Index);

        app(IndexJobHandler::class)->handle($job, $this->makeContext($job));
        $result = app(IndexJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertTrue($result->resultData['already_indexed'] ?? false);
        $this->assertSame(1, (int) SearchIndexState::where('content_id', $content->id)->value('index_version'));
    }

    /** PUBLISH 픽스처 — 필수 job 전체 SUCCESS + 필수 rendition 3종 (파일 포함) */
    private function makePublishFixture(): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Publish);

        foreach (['TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX'] as $type) {
            WorkflowJob::factory()->success()->ofType(JobType::from($type))->create([
                'instance_id' => $job->instance_id, 'content_id' => $content->id,
            ]);
        }

        $renditions = [
            ['MASTER', 'MASTER', "master/2026/07/{$content->id}.mov"],
            ['PROXY_VIDEO', 'PROXY', "proxy/2026/07/{$content->id}_720p.mp4"],
            ['THUMBNAIL', 'THUMBNAIL', "thumb/2026/07/{$content->id}.webp"],
        ];

        foreach ($renditions as [$type, $zone, $path]) {
            $this->putZoneFile(StorageZone::from($zone), $path, "bytes-{$type}");
            MediaRendition::create([
                'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
                'rendition_type' => $type, 'storage_zone' => $zone, 'path' => $path,
            ]);
        }

        return [$job, $content];
    }

    public function test_publish_transitions_content_ready_and_instance_success(): void
    {
        [$job, $content] = $this->makePublishFixture();

        $result = app(PublishJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');

        $freshContent = $content->fresh();
        $this->assertSame('READY', $freshContent->status->value);
        $this->assertNotNull($freshContent->published_at);
        $this->assertSame('SUCCESS', $job->instance->fresh()->status->value);
        $this->assertNotNull($job->instance->fresh()->finished_at);
    }

    public function test_publish_refuses_when_required_rendition_file_missing(): void
    {
        [$job, $content] = $this->makePublishFixture();

        // THUMBNAIL 파일만 유실 (행은 존재 — 스토리지 파일 실재 stat 이중 확인 검증)
        $thumb = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'THUMBNAIL')->firstOrFail();
        unlink($this->storage->absolutePath(StorageZone::Thumbnail, $thumb->path));

        $result = app(PublishJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('REQUIRED_RENDITION_MISSING', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
        $this->assertSame('PROCESSING', $content->fresh()->status->value); // 게시 미수행
    }

    public function test_publish_blocked_when_required_job_incomplete(): void
    {
        [$job, $content] = $this->makePublishFixture();

        // TC를 RUNNING으로 재구성 (필수 job 미완 상황)
        DB::table('workflow_jobs')
            ->where('instance_id', $job->instance_id)->where('job_type', 'TC')
            ->delete();
        WorkflowJob::factory()->running()->ofType(JobType::Tc)->create([
            'instance_id' => $job->instance_id, 'content_id' => $content->id,
        ]);

        $result = app(PublishJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('PUBLISH_BLOCKED', $result->failReasonCode);
        $this->assertSame('PROCESSING', $content->fresh()->status->value);
    }

    public function test_publish_rerun_is_idempotent(): void
    {
        [$job, $content] = $this->makePublishFixture();

        app(PublishJobHandler::class)->handle($job, $this->makeContext($job));
        $result = app(PublishJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertTrue($result->resultData['already_published'] ?? false);
        $this->assertSame('READY', $content->fresh()->status->value);
    }

    public function test_cleanup_removes_temp_and_scratch_but_never_master(): void
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Cleanup, mediaFileAttributes: [
            'temp_path' => 'temp/upload.mov',
        ]);
        $tempAbs = $this->putTempUpload('temp/upload.mov', 'upload-bytes');

        // MASTER 파일 + 형제 job 스크래치 + progress 행
        $masterAbs = $this->putZoneFile(StorageZone::Master, "master/2026/07/{$content->id}.mov", 'master-bytes');
        $sibling = WorkflowJob::factory()->success()->ofType(JobType::Tc)->create([
            'instance_id' => $job->instance_id, 'content_id' => $content->id,
        ]);
        $scratch = $this->storage->tempFilePath(StorageZone::Proxy, $sibling->id, 'partial.mp4');
        file_put_contents($scratch, 'partial');
        DB::table('workflow_job_progresses')->insert([
            'job_id' => $sibling->id, 'progress_percent' => 100, 'updated_at' => now(),
        ]);

        $result = app(CleanupJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertFileDoesNotExist($tempAbs);
        $this->assertNull($mediaFile->fresh()->temp_path);
        $this->assertFileDoesNotExist($scratch);
        $this->assertDatabaseMissing('workflow_job_progresses', ['job_id' => $sibling->id]);
        $this->assertFileExists($masterAbs); // MASTER는 어떤 경우에도 삭제 금지
    }
}
