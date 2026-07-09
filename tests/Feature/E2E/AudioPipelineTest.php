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
 * M6 AUDIO 파이프라인 E2E — 오디오 업로드 → TM → VERIFY → MA(AUDIO 판정) → AUDIO_TC →
 * INDEX → PUBLISH → CLEANUP → contents.status = READY (Job Type Def §4 AUDIO 체인).
 *
 * 실제 ffmpeg/ffprobe를 사용한다 — 미설치 환경에서는 skip.
 * WAVEFORM(구현됨·AUDIO worker 전담)·STT(작업 정의 미확정)는 이 worker의
 * supported_job_types에 없어 READY 잔존이 정상이다 (SM Spec §7 비차단).
 */
class AudioPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    private MediaStorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ffmpeg', 'ffprobe'] as $tool) {
            $probe = new Process([config("workflow.tools.{$tool}", $tool), '-version']);
            $probe->run();
            if (! $probe->isSuccessful()) {
                $this->markTestSkipped('ffmpeg/ffprobe not available');
            }
        }

        $this->seed();

        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf-e2e-audio-'.getmypid().'-'.uniqid();
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

    public function test_uploaded_audio_reaches_ready_automatically(): void
    {
        // 실제 3초 사인파 WAV 생성 → TEMP zone 업로드 배치
        $tempDir = $this->storage->zonePath(StorageZone::Temp).DIRECTORY_SEPARATOR.'temp';
        @mkdir($tempDir, 0775, true);
        $uploadAbs = $tempDir.DIRECTORY_SEPARATOR.'e2e-upload.wav';

        $gen = new Process([
            (string) config('workflow.tools.ffmpeg'), '-y',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
            '-c:a', 'pcm_s16le', $uploadAbs,
        ]);
        $gen->setTimeout(60);
        $gen->run();
        $this->assertTrue($gen->isSuccessful(), 'test audio generation failed: '.$gen->getErrorOutput());

        $content = Content::factory()->registered()->create([
            'title' => 'E2E 오디오', 'created_by' => User::factory(),
        ]);
        MediaFile::factory()->for($content)->create([
            'original_filename' => 'e2e-upload.wav', 'ext' => 'wav',
            'temp_path' => 'temp/e2e-upload.wav',
            'file_size' => filesize($uploadAbs),
            'checksum' => hash_file('sha256', $uploadAbs),
        ]);

        $template = WorkflowTemplate::where('code', 'AUDIO_INGEST')->firstOrFail();

        DB::transaction(function () use ($content, $template) {
            app(WorkflowInstanceFactory::class)->createForContent($content, $template);
            app(ContentStateMachine::class)->transition($content, 'PROCESSING', 'cms-web', 'ingest');
        });

        $agent = WorkflowWorkerAgent::factory()->online()->create([
            'worker_name' => 'e2e-audio-all-in-one',
            'supported_job_types' => ['TM', 'VERIFY', 'MA', 'AUDIO_TC', 'INDEX', 'PUBLISH', 'CLEANUP'],
        ]);

        $scheduler = app(WorkflowSchedulerService::class);
        $runner = app(WorkerRunner::class);

        $pipelineDone = fn (): bool => $content->fresh()->status === ContentStatus::Ready
            && DB::table('workflow_jobs')->where('content_id', $content->id)
                ->where('job_type', 'CLEANUP')->value('status') === 'SUCCESS';

        for ($i = 0; $i < 21 && ! $pipelineDone(); $i++) {
            $scheduler->tick();
            $runner->run($agent, once: true);
        }

        $freshContent = $content->fresh();
        $this->assertSame('READY', $freshContent->status->value, 'content가 READY에 도달하지 못했다: '.
            json_encode(DB::table('workflow_jobs')->where('content_id', $content->id)
                ->pluck('status', 'job_type')));
        $this->assertSame('AUDIO', $freshContent->media_type->value);

        // 필수 job 전체 SUCCESS
        $statuses = DB::table('workflow_jobs')->where('content_id', $content->id)
            ->pluck('status', 'job_type');
        foreach (['TM', 'VERIFY', 'MA', 'AUDIO_TC', 'INDEX', 'PUBLISH', 'CLEANUP'] as $type) {
            $this->assertSame('SUCCESS', $statuses[$type], "{$type}가 SUCCESS가 아니다");
        }

        // 필수 rendition — AUDIO는 MASTER + PROXY_AUDIO (SM Spec §8, 썸네일 없음)
        foreach (['MASTER', 'PROXY_AUDIO'] as $type) {
            $rendition = MediaRendition::where('content_id', $content->id)
                ->where('rendition_type', $type)->first();
            $this->assertNotNull($rendition, "{$type} rendition row 누락");
            $this->assertFileExists(
                $this->storage->absolutePath($rendition->storage_zone, $rendition->path),
                "{$type} 파일 누락",
            );
        }

        // PROXY_AUDIO 계약 — 프로파일 기반·원본 길이 ±0.5s (Job Type Def §3.6)
        $proxy = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'PROXY_AUDIO')->firstOrFail();
        $this->assertSame('default', $proxy->variant_key);
        $this->assertNotNull($proxy->profile_id);
        $this->assertEqualsWithDelta(3000, (int) $proxy->duration_ms, 500);

        // INDEX — search_index_states INDEXED
        $this->assertSame('INDEXED', DB::table('search_index_states')
            ->where('content_id', $content->id)->value('status'));

        // 선택 step(WAVEFORM·STT)은 이 worker 담당이 아니다 — 종결되지 않아도 READY를 막지 않는다
        $this->assertContains($statuses['WAVEFORM'], ['WAITING', 'READY']);
        $this->assertContains($statuses['STT'], ['WAITING', 'READY']);

        // CLEANUP — 업로드 temp 삭제
        $this->assertFileDoesNotExist($uploadAbs);
    }
}
