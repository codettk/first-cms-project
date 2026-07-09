<?php

/**
 * 로컬 리허설 공용 헬퍼 — workflow-release-gate-checklist §2·§3 실환경 근사 절차.
 *
 * 실행: 리허설 전용 DB·storage·검색 index를 shell env로 지정한 뒤
 *   php artisan tinker scripts/ops/rehearsal/rehearsal-part{1,2,3}.php
 * (DB_DATABASE=first_cms_rehearsal, WORKFLOW_SEARCH_DRIVER=elasticsearch,
 *  WORKFLOW_SEARCH_INDEX=content_mam_rehearsal, WORKFLOW_STORAGE_ROOT=임시 경로)
 */

use App\Enums\StorageZone;
use App\Models\Content;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\WorkerAgentService;
use App\Services\Workflow\Worker\WorkerRunner;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

const REHEARSAL_VIDEO_TYPES = ['TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'];

$GLOBALS['rehearsal_results'] = [];

function rehearsalCheck(string $name, bool $ok, string $note = ''): void
{
    $GLOBALS['rehearsal_results'][] = $ok;
    echo ($ok ? '[PASS] ' : '[FAIL] ').$name.($note !== '' ? " — {$note}" : '').PHP_EOL;
}

function rehearsalSummary(): void
{
    $fail = count(array_filter($GLOBALS['rehearsal_results'], fn ($ok) => ! $ok));
    echo 'REHEARSAL_RESULT='.($fail === 0 ? 'ALL_PASS' : "{$fail}_FAILED").PHP_EOL;
}

function rehearsalEnvBanner(): void
{
    echo 'db='.config('database.connections.pgsql.database')
        .' driver='.get_class(app(\App\Services\Workflow\Search\SearchIndexClient::class))
        .' index='.config('workflow.search.index')
        .' storage='.config('workflow.storage.root').PHP_EOL;
}

function registerUpload(string $label, string $file, string $abs): Content
{
    $content = Content::factory()->registered()->create(['title' => $label, 'created_by' => User::factory()]);
    MediaFile::factory()->for($content)->create([
        'original_filename' => $file, 'ext' => 'mp4',
        'temp_path' => 'temp/'.$file,
        'file_size' => filesize($abs), 'checksum' => hash_file('sha256', $abs),
    ]);

    $template = WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();
    DB::transaction(function () use ($content, $template) {
        app(WorkflowInstanceFactory::class)->createForContent($content, $template);
        app(ContentStateMachine::class)->transition($content, 'PROCESSING', 'cms-web', 'ingest');
    });

    return $content;
}

function makeVideoUpload(string $label, int $seconds, string $size = '640x360'): Content
{
    $tempDir = app(MediaStorageService::class)->zonePath(StorageZone::Temp).DIRECTORY_SEPARATOR.'temp';
    @mkdir($tempDir, 0775, true);
    $file = $label.'.mp4';
    $abs = $tempDir.DIRECTORY_SEPARATOR.$file;

    $gen = new Process([(string) config('workflow.tools.ffmpeg'), '-y',
        '-f', 'lavfi', '-i', "testsrc=duration={$seconds}:size={$size}:rate=25",
        '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', $abs]);
    $gen->setTimeout(600);
    $gen->mustRun();

    return registerUpload($label, $file, $abs);
}

function makeCorruptUpload(string $label): Content
{
    $tempDir = app(MediaStorageService::class)->zonePath(StorageZone::Temp).DIRECTORY_SEPARATOR.'temp';
    @mkdir($tempDir, 0775, true);
    $file = $label.'.mp4';
    $abs = $tempDir.DIRECTORY_SEPARATOR.$file;
    file_put_contents($abs, 'this-is-not-a-video-'.str_repeat('x', 4096));

    return registerUpload($label, $file, $abs);
}

function inlineAgent(string $name, array $types)
{
    return app(WorkerAgentService::class)->register($name, 'GENERAL', $types, version: '1.0.0');
}

/** tick + 1회 claim 실행 루프 — READY/FAILED 종결 시 중단 */
function driveInline(Content $content, $agent, int $loops = 30): void
{
    $scheduler = app(WorkflowSchedulerService::class);
    $runner = app(WorkerRunner::class);

    for ($i = 0; $i < $loops; $i++) {
        $scheduler->tick();
        $runner->run($agent, once: true);
        if (in_array($content->fresh()->status->value, ['READY', 'FAILED'], true)
            && jobTerminalCount($content) === 0) {
            return;
        }
    }
}

function jobTerminalCount(Content $content): int
{
    return DB::table('workflow_jobs')->where('content_id', $content->id)
        ->whereIn('status', ['WAITING', 'READY', 'RUNNING', 'RETRY'])->count();
}

function jobRow(Content $content, string $type): ?object
{
    return DB::table('workflow_jobs')->where('content_id', $content->id)
        ->where('job_type', $type)->orderByDesc('id')->first();
}

function jobStatus(Content $content, string $type): ?string
{
    return jobRow($content, $type)?->status;
}

/** 리허설 대기 단축 — RETRY 백오프 도래 시각만 앞당긴다(상태 전이는 Scheduler 소관) */
function resumeRetries(Content $content): void
{
    DB::table('workflow_jobs')->where('content_id', $content->id)
        ->where('status', 'RETRY')->update(['next_attempt_at' => now()]);
}

/** 실제 worker 데몬 프로세스 기동 — 리허설 환경 env를 상속한다 */
function spawnWorker(string $name, array $types): Process
{
    $p = new Process(
        [PHP_BINARY, base_path('artisan'), 'workflow:worker',
            "--name={$name}", '--job-types='.implode(',', $types)],
        base_path(),
    );
    $p->setTimeout(null);
    $p->start();

    return $p;
}
