<?php

/** 리허설 2부 — Worker kill·Scheduler 재기동 복구(§6-1/§6-21) · 콘텐츠 취소 정리(§6-24) */

use App\Models\User;
use App\Services\Workflow\Admin\AuditContext;
use App\Services\Workflow\Admin\Command\ContentCancelService;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\Worker\WorkerRunner;
use Illuminate\Support\Facades\DB;

require __DIR__.'/rehearsal-lib.php';

rehearsalEnvBanner();
$scheduler = app(WorkflowSchedulerService::class);
$runner = app(WorkerRunner::class);

/** MA SUCCESS까지 인라인 진행 후, 실제 worker 데몬이 TC를 RUNNING으로 잡을 때까지 대기 */
function bringToRunningTc($content, string $workerName)
{
    $scheduler = app(WorkflowSchedulerService::class);
    $runner = app(WorkerRunner::class);
    $pre = inlineAgent($workerName.'-pre', ['TM', 'VERIFY', 'MA']);
    for ($i = 0; $i < 15 && jobStatus($content, 'MA') !== 'SUCCESS'; $i++) {
        $scheduler->tick();
        $runner->run($pre, once: true);
    }
    if (jobStatus($content, 'MA') !== 'SUCCESS') {
        throw new RuntimeException('전처리(MA)까지 도달 실패');
    }

    $worker = spawnWorker($workerName, ['TC']);
    for ($i = 0; $i < 60 && jobStatus($content, 'TC') !== 'RUNNING'; $i++) {
        $scheduler->tick();
        sleep(2);
    }
    if (jobStatus($content, 'TC') !== 'RUNNING') {
        $worker->stop(0);
        throw new RuntimeException('TC RUNNING 도달 실패');
    }

    return $worker;
}

echo '--- S3 Worker kill + Scheduler 재기동 첫 틱 복구 (Runbook §6-1·§6-21) ---'.PHP_EOL;
$c3 = makeVideoUpload('rehearsal-s3', 240);
$worker3 = bringToRunningTc($c3, 'rehearsal-s3-tc');
$worker3->stop(0); // 프로세스 강제 종료 — heartbeat 상실 재현
echo 'worker killed — scheduler 정지 상태로 100초 대기(heartbeat 임계 90초 경과)'.PHP_EOL;
sleep(100);

$scheduler->tick(); // 재기동 첫 틱

$tc3 = jobRow($c3, 'TC');
rehearsalCheck('S3 첫 틱에 고아 RUNNING 회수 → RETRY',
    $tc3->status === 'RETRY' && in_array($tc3->fail_reason_code, ['WORKER_CRASH', 'LEASE_EXPIRED'], true),
    "status={$tc3->status} reason=".($tc3->fail_reason_code ?? '?'));
rehearsalCheck('S3 죽은 worker OFFLINE 판정', DB::table('workflow_worker_agents')
    ->where('worker_name', 'rehearsal-s3-tc')->value('status') === 'OFFLINE');
rehearsalCheck('S3 lock 회수', ! DB::table('workflow_job_locks')->where('job_id', $tc3->id)->exists());
rehearsalCheck('S3 WORKER_OFFLINE alert 영속화', DB::table('workflow_alerts')
    ->where('event_type', 'WORKER_OFFLINE')->exists());

resumeRetries($c3);
$agent3 = inlineAgent('rehearsal-s3-post', REHEARSAL_VIDEO_TYPES);
driveInline($c3, $agent3, 40);
rehearsalCheck('S3 다른 worker 재실행으로 READY 복구', $c3->fresh()->status->value === 'READY');
rehearsalCheck('S3 TC retry_count 증가', ((int) jobRow($c3, 'TC')->retry_count) >= 1);

echo '--- S6 처리 중 콘텐츠 취소 정리 (Runbook §6-24) ---'.PHP_EOL;
// 720p 장초수 — TC가 취소 무반응 강제 임계(30초)보다 오래 실행되도록 한다
$c6 = makeVideoUpload('rehearsal-s6', 600, '1280x720');
$worker6 = bringToRunningTc($c6, 'rehearsal-s6-tc');

// 관리자 취소 — 관리자 조작 문맥(감사 기록 활성) 재현
$admin = User::factory()->create();
app(AuditContext::class)->capture($admin->id, '127.0.0.1', 'rehearsal');
app(AuditContext::class)->enableWrite();
app(ContentCancelService::class)->execute($c6->fresh(), '리허설 취소', $admin);

for ($i = 0; $i < 60 && jobStatus($c6, 'TC') !== 'CANCELED'; $i++) {
    $scheduler->tick();
    sleep(2);
}
$worker6->stop(0);

// 계약(Controller Service Spec §10·§16): 비종결 job은 CANCELED, RUNNING은
// cancel_requested 마킹 후 Worker 협조 취소 또는 30초 무반응 강제 전이(SM Spec §11)
rehearsalCheck('S6 RUNNING TC 취소 종결(무반응 강제 포함)', jobStatus($c6, 'TC') === 'CANCELED');
rehearsalCheck('S6 비종결 후속 job CANCELED', jobStatus($c6, 'PUBLISH') === 'CANCELED');
rehearsalCheck('S6 인스턴스 CANCELED', DB::table('workflow_instances')
    ->where('content_id', $c6->id)->value('status') === 'CANCELED');
rehearsalCheck('S6 콘텐츠 FAILED(canceled)', $c6->fresh()->status->value === 'FAILED');
rehearsalCheck('S6 취소 감사 로그 기록', DB::table('admin_audit_logs')
    ->where('action', 'content.cancel')->where('actor_user_id', $admin->id)->exists());

rehearsalSummary();
