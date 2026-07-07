<?php

namespace Tests\Feature\E2E;

use App\Enums\ContentStatus;
use App\Enums\StorageZone;
use App\Models\Content;
use App\Models\MediaFile;
use App\Models\MediaRendition;
use App\Models\SearchIndexState;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\WorkerRunner;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * MVP 성공 시나리오 E2E — 영상 1건 업로드 → TM → VERIFY → MA → TC → CA → INDEX →
 * PUBLISH → CLEANUP → contents.status = READY 자동 전환 (Roadmap Phase 5 완료 기준).
 *
 * 실제 ffmpeg/ffprobe를 사용한다 — 미설치 환경에서는 skip.
 */
class VideoPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    private MediaStorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }

        $this->seed();

        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf-e2e-'.getmypid().'-'.uniqid();
        config(['workflow.storage.root' => $this->storageRoot]);
        config(['workflow.storage.zones' => array_fill_keys(
            ['TEMP', 'MASTER', 'PROXY', 'THUMBNAIL', 'CATALOG', 'DOCUMENT', 'ARCHIVE'], null
        )]);

        $this->storage = app(MediaStorageService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->storageRoot) && is_dir($this->storageRoot)) {
            exec(PHP_OS_FAMILY === 'Windows'
                ? 'rmdir /s /q "'.$this->storageRoot.'"'
                : 'rm -rf "'.$this->storageRoot.'"');
        }

        parent::tearDown();
    }

    private function ffmpegAvailable(): bool
    {
        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            $probe = new Process([config("workflow.tools.{$tool}", $tool), '-version']);
            $probe->run();
            if (! $probe->isSuccessful()) {
                return false;
            }
        }

        return true;
    }

    /** 실제 3초 테스트 영상 생성 → TEMP zone 업로드 배치 */
    private function makeUploadedVideo(): string
    {
        $tempDir = $this->storage->zonePath(StorageZone::Temp).DIRECTORY_SEPARATOR.'temp';
        @mkdir($tempDir, 0775, true);
        $uploadAbs = $tempDir.DIRECTORY_SEPARATOR.'e2e-upload.mp4';

        $gen = new Process([
            (string) config('workflow.tools.ffmpeg'), '-y',
            '-f', 'lavfi', '-i', 'testsrc=duration=3:size=640x360:rate=30',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest',
            $uploadAbs,
        ]);
        $gen->setTimeout(120);
        $gen->run();

        $this->assertTrue($gen->isSuccessful(), 'test video generation failed: '.$gen->getErrorOutput());

        return $uploadAbs;
    }

    public function test_uploaded_video_reaches_ready_automatically(): void
    {
        $uploadAbs = $this->makeUploadedVideo();

        // ── 업로드/등록 (CMS Web 시뮬레이션): REGISTERED 콘텐츠 + media_file + 인스턴스 생성 + PROCESSING
        $content = Content::factory()->registered()->create([
            'title' => 'E2E 영상', 'created_by' => User::factory(),
        ]);
        MediaFile::factory()->for($content)->create([
            'original_filename' => 'e2e-upload.mp4', 'ext' => 'mp4',
            'temp_path' => 'temp/e2e-upload.mp4',
            'file_size' => filesize($uploadAbs),
            'checksum' => hash_file('sha256', $uploadAbs),
        ]);

        $template = WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();

        DB::transaction(function () use ($content, $template) {
            app(WorkflowInstanceFactory::class)->createForContent($content, $template);
            app(ContentStateMachine::class)->transition($content, 'PROCESSING', 'cms-web', 'ingest');
        });

        // ── Scheduler 틱 + Worker 루프 반복 — READY 도달까지 (job 8종 순차 소비)
        $agent = WorkflowWorkerAgent::factory()->online()->create([
            'worker_name' => 'e2e-all-in-one',
            'supported_job_types' => ['TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'],
        ]);

        $scheduler = app(WorkflowSchedulerService::class);
        $runner = app(WorkerRunner::class);

        $pipelineDone = fn (): bool => $content->fresh()->status === ContentStatus::Ready
            && DB::table('workflow_jobs')->where('content_id', $content->id)
                ->where('job_type', 'CLEANUP')->value('status') === 'SUCCESS';

        for ($i = 0; $i < 24 && ! $pipelineDone(); $i++) {
            $scheduler->tick();
            $runner->run($agent, once: true);
        }

        // ── 최종 상태 검증
        $freshContent = $content->fresh();
        $this->assertSame('READY', $freshContent->status->value, 'content가 READY에 도달하지 못했다: '.
            json_encode(DB::table('workflow_jobs')->where('content_id', $content->id)
                ->pluck('status', 'job_type')));
        $this->assertSame('VIDEO', $freshContent->media_type->value);
        $this->assertNotNull($freshContent->published_at);

        $instance = $content->instances()->latest('id')->first()
            ?? \App\Models\WorkflowInstance::where('content_id', $content->id)->firstOrFail();
        $this->assertSame('SUCCESS', $instance->fresh()->status->value);

        // job 8종 전체 SUCCESS
        $statuses = DB::table('workflow_jobs')->where('content_id', $content->id)
            ->pluck('status', 'job_type');
        foreach (['TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'] as $type) {
            $this->assertSame('SUCCESS', $statuses[$type], "{$type}가 SUCCESS가 아니다");
        }

        // 필수 rendition — DB 행 + 파일 실재
        foreach (['MASTER', 'PROXY_VIDEO', 'THUMBNAIL'] as $type) {
            $rendition = MediaRendition::where('content_id', $content->id)
                ->where('rendition_type', $type)->first();
            $this->assertNotNull($rendition, "{$type} rendition row 누락");
            $this->assertFileExists(
                $this->storage->absolutePath($rendition->storage_zone, $rendition->path),
                "{$type} 파일 누락",
            );
        }

        // PROXY_VIDEO 계약 검증 — profile 기반 산출
        $proxy = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'PROXY_VIDEO')->firstOrFail();
        $this->assertSame('720p', $proxy->variant_key);
        $this->assertNotNull($proxy->profile_id);
        $this->assertEqualsWithDelta(3000, (int) $proxy->duration_ms, 1000);

        // INDEX — search_index_states INDEXED
        $indexState = SearchIndexState::where('content_id', $content->id)->firstOrFail();
        $this->assertSame('INDEXED', $indexState->status->value);

        // CLEANUP — 업로드 temp 삭제 + temp_path NULL + progress 잔여 0
        $this->assertFileDoesNotExist($uploadAbs);
        $this->assertNull($content->mediaFiles()->first()->temp_path);
        $this->assertSame(0, DB::table('workflow_job_progresses')
            ->whereIn('job_id', DB::table('workflow_jobs')->where('content_id', $content->id)->pluck('id'))
            ->count());

        // Master 보호 — MASTER 파일은 보존
        $master = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'MASTER')->firstOrFail();
        $this->assertFileExists($this->storage->absolutePath('MASTER', $master->path));

        // 모든 전이가 history로 기록되었는지 표본 확인 (생성 + claim + 종결)
        $tmJobId = DB::table('workflow_jobs')->where('content_id', $content->id)
            ->where('job_type', 'TM')->value('id');
        $this->assertGreaterThanOrEqual(4, DB::table('workflow_job_histories')
            ->where('job_id', $tmJobId)->count());
    }
}
