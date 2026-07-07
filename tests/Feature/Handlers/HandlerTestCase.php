<?php

namespace Tests\Feature\Handlers;

use App\Enums\JobType;
use App\Enums\StorageZone;
use App\Models\Content;
use App\Models\MediaFile;
use App\Models\WorkflowInstance;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Queue\JobLockService;
use App\Services\Workflow\Queue\JobProgressService;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\CancellationToken;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobLogService;
use App\Services\Workflow\Worker\Tools\ToolRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeToolRunner;
use Tests\TestCase;

abstract class HandlerTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeToolRunner $tools;

    protected MediaStorageService $storage;

    protected string $storageRoot;

    protected WorkflowWorkerAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf-handler-test-'.getmypid().'-'.uniqid();
        config(['workflow.storage.root' => $this->storageRoot]);
        config(['workflow.storage.zones' => array_fill_keys(
            ['TEMP', 'MASTER', 'PROXY', 'THUMBNAIL', 'CATALOG', 'DOCUMENT', 'ARCHIVE'], null
        )]);

        $this->tools = new FakeToolRunner;
        $this->app->instance(ToolRunner::class, $this->tools);

        $this->storage = app(MediaStorageService::class);
        $this->agent = WorkflowWorkerAgent::factory()->online()->create();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageRoot)) {
            exec(PHP_OS_FAMILY === 'Windows'
                ? 'rmdir /s /q "'.$this->storageRoot.'"'
                : 'rm -rf "'.$this->storageRoot.'"');
        }

        parent::tearDown();
    }

    /**
     * VIDEO 콘텐츠 + 인스턴스 + 지정 유형 job(RUNNING) 픽스처.
     *
     * @return array{0: WorkflowJob, 1: Content, 2: MediaFile}
     */
    protected function makeRunningJob(JobType $type, array $jobAttributes = [], array $mediaFileAttributes = []): array
    {
        $content = Content::factory()->processing()->create();
        $mediaFile = MediaFile::factory()->for($content)->create($mediaFileAttributes);
        $instance = WorkflowInstance::factory()->for($content, 'content')->create();

        $job = WorkflowJob::factory()->running()->create([
            'instance_id' => $instance->id,
            'content_id' => $content->id,
            'job_type' => $type,
            'worker_id' => $this->agent->id,
            ...$jobAttributes,
        ]);

        return [$job, $content, $mediaFile];
    }

    protected function makeContext(WorkflowJob $job): JobExecutionContext
    {
        return new JobExecutionContext(
            job: $job,
            worker: $this->agent,
            logger: app(JobLogService::class),
            progress: app(JobProgressService::class),
            cancelToken: new CancellationToken($job, $this->agent->id, app(JobLockService::class)),
        );
    }

    /** TEMP zone에 업로드 원본 파일 배치 */
    protected function putTempUpload(string $relativePath, string $contents): string
    {
        $abs = $this->storage->zonePath(StorageZone::Temp).DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $contents);

        return $abs;
    }

    /** 지정 zone 경로에 파일 배치 (rendition 픽스처용) */
    protected function putZoneFile(StorageZone $zone, string $relativePath, string $contents): string
    {
        $abs = $this->storage->zonePath($zone).DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $contents);

        return $abs;
    }
}
