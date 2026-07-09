<?php

/** 리허설 3부 — 검색엔진 중단/백오프 회복(§6-15 + 체크리스트 §6 실 driver 검증) · T5 부하 */

use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\Search\SearchIndexClient;
use App\Services\Workflow\Worker\WorkerRunner;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/rehearsal-lib.php';

rehearsalEnvBanner();
$scheduler = app(WorkflowSchedulerService::class);
$runner = app(WorkerRunner::class);

echo '--- S5 검색엔진 중단 → 백오프 → 재기동 회복 (Runbook §6-15 · 체크리스트 §6) ---'.PHP_EOL;
$c5 = makeVideoUpload('rehearsal-s5', 3);
(new Process(['docker', 'stop', 'first-cms-es']))->setTimeout(120)->mustRun();
echo 'elasticsearch container stopped'.PHP_EOL;

$agent5 = inlineAgent('rehearsal-s5', REHEARSAL_VIDEO_TYPES);
driveInline($c5, $agent5, 20); // INDEX에서 막힌다

$idx = jobRow($c5, 'INDEX');
rehearsalCheck('S5 엔진 중단 시 INDEX_UNAVAILABLE 백오프',
    $idx->status === 'RETRY' && $idx->fail_reason_code === 'INDEX_UNAVAILABLE',
    "status={$idx->status} reason=".($idx->fail_reason_code ?? '?'));
rehearsalCheck('S5 백오프 예약(next_attempt_at)', $idx->next_attempt_at !== null);

(new Process(['docker', 'start', 'first-cms-es']))->setTimeout(120)->mustRun();
$client = app(SearchIndexClient::class);
$engineUp = false;
for ($i = 0; $i < 45; $i++) {
    try {
        $client->upsert('rehearsal-health-check', ['ok' => true]);
        $engineUp = true;
        break;
    } catch (\Throwable) {
        sleep(2);
    }
}
rehearsalCheck('S5 엔진 재기동 확인', $engineUp);

resumeRetries($c5);
driveInline($c5, $agent5, 25);
rehearsalCheck('S5 회복 후 자동 READY', $c5->fresh()->status->value === 'READY');
rehearsalCheck('S5 search_index_states INDEXED 회복', DB::table('search_index_states')
    ->where('content_id', $c5->id)->value('status') === 'INDEXED');
rehearsalCheck('S5 운영형 driver로 엔진에서 색인 문서 조회(§6-4)',
    $client->get('content-'.$c5->id) !== null);

echo '--- S7 T5 부하 — 동시 10건 · 실제 worker 데몬 3대 (실 검색엔진 포함) ---'.PHP_EOL;
$contents = [];
for ($i = 1; $i <= 10; $i++) {
    $contents[] = makeVideoUpload("rehearsal-t5-{$i}", 3);
}
$ids = array_map(fn ($c) => $c->id, $contents);

$workers = [
    spawnWorker('rehearsal-t5-w1', REHEARSAL_VIDEO_TYPES),
    spawnWorker('rehearsal-t5-w2', REHEARSAL_VIDEO_TYPES),
    spawnWorker('rehearsal-t5-w3', REHEARSAL_VIDEO_TYPES),
];

$allDone = false;
for ($i = 0; $i < 150 && ! $allDone; $i++) {
    $scheduler->tick();
    sleep(2);
    $ready = DB::table('contents')->whereIn('id', $ids)->where('status', 'READY')->count();
    $pending = DB::table('workflow_jobs')->whereIn('content_id', $ids)
        ->where('status', '<>', 'SUCCESS')->count();
    $allDone = ($ready === 10 && $pending === 0);
    if ($i % 15 === 0) {
        echo "t5 progress: ready={$ready}/10 pending_jobs={$pending}".PHP_EOL;
    }
}
foreach ($workers as $w) {
    $w->stop(0);
}

rehearsalCheck('S7 동시 10건 전건 READY + 80 job SUCCESS', $allDone);
$dup = DB::select(
    "SELECT h.job_id FROM workflow_job_histories h
     JOIN workflow_jobs j ON j.id = h.job_id
     WHERE j.content_id = ANY(?) AND h.to_status = 'SUCCESS'
     GROUP BY h.job_id HAVING count(*) > 1",
    ['{'.implode(',', $ids).'}'],
);
rehearsalCheck('S7 이중 실행(중복 SUCCESS 전이) 0건', $dup === []);

rehearsalSummary();
