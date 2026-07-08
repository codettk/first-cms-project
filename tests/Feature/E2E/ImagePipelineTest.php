<?php

namespace Tests\Feature\E2E;

use App\Enums\ContentStatus;
use App\Enums\StorageZone;
use App\Models\Content;
use App\Models\MediaFile;
use App\Models\MediaRendition;
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
 * M6 IMAGE 파이프라인 E2E — 이미지 업로드 → TM → VERIFY → MA(IMAGE 판정) → IMAGE_TC →
 * CA(썸네일만) → INDEX → PUBLISH → CLEANUP → READY (Job Type Def §4 IMAGE 체인).
 *
 * IMAGE_TC·CA는 실제 vipsthumbnail을 사용한다(Transcode Profile Spec §12) —
 * ffmpeg/ffprobe/vipsthumbnail 미설치 환경에서는 skip. 도구 격리 검증은
 * ImageAudioHandlerTest(FakeToolRunner)가 커버한다.
 */
class ImagePipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    private MediaStorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['ffmpeg', '-version'], ['ffprobe', '-version'], ['vipsthumbnail', '--vips-version']] as [$tool, $flag]) {
            $probe = new Process([config("workflow.tools.{$tool}", $tool), $flag]);
            $probe->run();
            if (! $probe->isSuccessful()) {
                $this->markTestSkipped("{$tool} not available");
            }
        }

        $this->seed();

        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf-e2e-image-'.getmypid().'-'.uniqid();
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

    public function test_uploaded_image_reaches_ready_automatically(): void
    {
        // 실제 테스트 PNG 생성 → TEMP zone 업로드 배치
        $tempDir = $this->storage->zonePath(StorageZone::Temp).DIRECTORY_SEPARATOR.'temp';
        @mkdir($tempDir, 0775, true);
        $uploadAbs = $tempDir.DIRECTORY_SEPARATOR.'e2e-upload.png';

        $gen = new Process([
            (string) config('workflow.tools.ffmpeg'), '-y',
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=640x360:rate=1',
            '-frames:v', '1', $uploadAbs,
        ]);
        $gen->setTimeout(60);
        $gen->run();
        $this->assertTrue($gen->isSuccessful(), 'test image generation failed: '.$gen->getErrorOutput());

        $content = Content::factory()->registered()->create([
            'title' => 'E2E 이미지', 'created_by' => User::factory(),
        ]);
        MediaFile::factory()->for($content)->create([
            'original_filename' => 'e2e-upload.png', 'ext' => 'png',
            'temp_path' => 'temp/e2e-upload.png',
            'file_size' => filesize($uploadAbs),
            'checksum' => hash_file('sha256', $uploadAbs),
        ]);

        $template = WorkflowTemplate::where('code', 'IMAGE_INGEST')->firstOrFail();

        DB::transaction(function () use ($content, $template) {
            app(WorkflowInstanceFactory::class)->createForContent($content, $template);
            app(ContentStateMachine::class)->transition($content, 'PROCESSING', 'cms-web', 'ingest');
        });

        $agent = WorkflowWorkerAgent::factory()->online()->create([
            'worker_name' => 'e2e-image-all-in-one',
            'supported_job_types' => ['TM', 'VERIFY', 'MA', 'IMAGE_TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'],
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

        $freshContent = $content->fresh();
        $this->assertSame('READY', $freshContent->status->value, 'content가 READY에 도달하지 못했다: '.
            json_encode(DB::table('workflow_jobs')->where('content_id', $content->id)
                ->pluck('status', 'job_type')));
        $this->assertSame('IMAGE', $freshContent->media_type->value);

        $statuses = DB::table('workflow_jobs')->where('content_id', $content->id)
            ->pluck('status', 'job_type');
        foreach (['TM', 'VERIFY', 'MA', 'IMAGE_TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'] as $type) {
            $this->assertSame('SUCCESS', $statuses[$type], "{$type}가 SUCCESS가 아니다");
        }

        // 필수 rendition — IMAGE는 MASTER + PROXY_IMAGE + THUMBNAIL (SM Spec §8)
        foreach (['MASTER', 'PROXY_IMAGE', 'THUMBNAIL'] as $type) {
            $rendition = MediaRendition::where('content_id', $content->id)
                ->where('rendition_type', $type)->first();
            $this->assertNotNull($rendition, "{$type} rendition row 누락");
            $this->assertFileExists(
                $this->storage->absolutePath($rendition->storage_zone, $rendition->path),
                "{$type} 파일 누락",
            );
        }

        // 카탈로그는 IMAGE에서 생성하지 않는다 (Job Type Def §4 매트릭스)
        $this->assertSame(0, MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'CATALOG')->count());

        $this->assertSame('INDEXED', DB::table('search_index_states')
            ->where('content_id', $content->id)->value('status'));
    }
}
