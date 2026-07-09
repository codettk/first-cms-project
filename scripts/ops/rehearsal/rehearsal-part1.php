<?php

/** 리허설 1부 — 필수 실패 판정/알림(§3) · Storage 차단 복구(§6-10) · Lock 타임아웃(§6-6/22) */

use App\Enums\StorageZone;
use App\Services\Workflow\Admin\Query\DashboardQueryService;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\WorkerRunner;
use Illuminate\Support\Facades\DB;

require __DIR__.'/rehearsal-lib.php';

rehearsalEnvBanner();
$scheduler = app(WorkflowSchedulerService::class);
$runner = app(WorkerRunner::class);

echo '--- S1 필수 job 최종 실패 판정/알림 (Runbook §3) ---'.PHP_EOL;
$c1 = makeCorruptUpload('rehearsal-s1-corrupt');
$agent1 = inlineAgent('rehearsal-s1', REHEARSAL_VIDEO_TYPES);
driveInline($c1, $agent1, 20);

rehearsalCheck('S1 content FAILED', $c1->fresh()->status->value === 'FAILED');
rehearsalCheck('S1 VERIFY 최종 FAILED', jobStatus($c1, 'VERIFY') === 'FAILED',
    'fail_reason='.(jobRow($c1, 'VERIFY')->fail_reason_code ?? '?'));
rehearsalCheck('S1 후속 필수 job SKIPPED', jobStatus($c1, 'PUBLISH') === 'SKIPPED');
rehearsalCheck('S1 REQUIRED_JOB_FAILED alert 영속화', DB::table('workflow_alerts')
    ->where('event_type', 'REQUIRED_JOB_FAILED')->where('related_content_id', $c1->id)->exists());
$banners = app(DashboardQueryService::class)->aggregate(refresh: true)['banners'];
rehearsalCheck('S1 대시보드 HIGH 배너 노출', collect($banners)
    ->contains(fn ($b) => $b['event_type'] === 'required_job_failed'));

echo '--- S2 Storage 차단 → 자동 복구 (Runbook §6-10) ---'.PHP_EOL;
$c2 = makeVideoUpload('rehearsal-s2', 3);
// zone 접두사 하위 디렉터리만 차단 — zonePath()는 zones env 미설정 시 공용 root를 반환한다
$masterPath = app(MediaStorageService::class)->zonePath(StorageZone::Master).DIRECTORY_SEPARATOR.'master';
if (is_dir($masterPath)) {
    rename($masterPath, $masterPath.'_blocked');
}
file_put_contents($masterPath, 'zone-blocked'); // mount 차단 재현 — 경로가 파일이라 쓰기 불가

$agent2 = inlineAgent('rehearsal-s2', REHEARSAL_VIDEO_TYPES);
$scheduler->tick();
$runner->run($agent2, once: true); // TM 시도 → storage 실패

$tm = jobRow($c2, 'TM');
rehearsalCheck('S2 차단 중 TM 재시도 대기(RETRY)', $tm->status === 'RETRY',
    'fail_reason='.($tm->fail_reason_code ?? '?'));

unlink($masterPath);
if (is_dir($masterPath.'_blocked')) {
    rename($masterPath.'_blocked', $masterPath);
}
resumeRetries($c2);
driveInline($c2, $agent2, 30);

rehearsalCheck('S2 복구 후 자동 READY', $c2->fresh()->status->value === 'READY');
rehearsalCheck('S2 TM retry_count 증가', ((int) jobRow($c2, 'TM')->retry_count) >= 1);

echo '--- S4 Lock 타임아웃 회수 (Runbook §6-6/22 — 픽스처 재현) ---'.PHP_EOL;
$c4 = makeVideoUpload('rehearsal-s4', 3);
$tc4 = jobRow($c4, 'TC');
DB::table('workflow_jobs')->where('id', $tc4->id)->update(['status' => 'READY']);
DB::table('workflow_jobs')->where('id', $tc4->id)
    ->update(['status' => 'RUNNING', 'started_at' => now()->subMinutes(30), 'timeout_sec' => 60]);
$w4 = inlineAgent('rehearsal-s4', ['TC']);
DB::table('workflow_job_locks')->insert([
    'job_id' => $tc4->id, 'locked_by_worker_id' => $w4->id,
    'locked_at' => now(), 'locked_until' => now()->addMinutes(5), 'heartbeat_at' => now(),
]);

$scheduler->tick();

$tc4 = jobRow($c4, 'TC');
rehearsalCheck('S4 TIMEOUT 경유 RETRY + TOOL_TIMEOUT',
    $tc4->status === 'RETRY' && $tc4->fail_reason_code === 'TOOL_TIMEOUT',
    "status={$tc4->status} reason=".($tc4->fail_reason_code ?? '?'));
rehearsalCheck('S4 만료 lock 회수', ! DB::table('workflow_job_locks')->where('job_id', $tc4->id)->exists());

rehearsalSummary();
