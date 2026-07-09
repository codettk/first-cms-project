<?php

namespace App\Console\Commands;

use App\Services\Workflow\Worker\WorkerAgentService;
use App\Services\Workflow\Worker\WorkerRunner;
use Illuminate\Console\Command;

/**
 * workflow:worker — Worker Agent Spec §4·§5·§18.
 * 초기 구현: 프로세스 1개 = Job 1개, 동시성은 supervisor numprocs.
 */
class WorkflowWorkerCommand extends Command
{
    protected $signature = 'workflow:worker
        {--name= : worker_name (기본: type-hostname-pid 조합)}
        {--worker-type=GENERAL : TM|GENERAL|TRANSCODE|IMAGE|AUDIO|DOCUMENT|OCR|INDEX (Worker Agent Spec §3)}
        {--job-types= : 콤마 구분 job_type 목록 — 미지정 시 worker-type 기본 매핑}
        {--once : 큐 1회 폴링 후 종료 (테스트·CI용)}
        {--max-jobs=0 : N건 처리 후 종료 (0 = 무제한)}';

    protected $description = 'Workflow Worker 데몬 — Claim → 실행 → 완료 기록 루프';

    /**
     * worker_type별 기본 supported_job_types — Worker Agent Spec §3 전체 매핑.
     * STT·AI_ANALYSIS는 스펙에 worker 배정이 없어(작업 정의 미확정) 어떤 유형에도 넣지 않는다.
     */
    private const array TYPE_MAP = [
        'TM' => ['TM'],
        'GENERAL' => ['VERIFY', 'MA', 'PUBLISH', 'CLEANUP'],
        'TRANSCODE' => ['TC'],
        'IMAGE' => ['IMAGE_TC', 'CA'],
        'AUDIO' => ['AUDIO_TC', 'WAVEFORM'],
        'DOCUMENT' => ['DOC_PREVIEW', 'TEXT_EXTRACT'],
        'OCR' => ['OCR'],
        'INDEX' => ['INDEX'],
    ];

    public function handle(WorkerAgentService $agents, WorkerRunner $runner): int
    {
        $type = strtoupper((string) $this->option('worker-type'));

        if (! array_key_exists($type, self::TYPE_MAP)) {
            $this->error("지원하지 않는 worker-type: {$type} (MVP: ".implode('|', array_keys(self::TYPE_MAP)).')');

            return self::FAILURE;
        }

        $jobTypes = $this->option('job-types')
            ? array_map('trim', explode(',', (string) $this->option('job-types')))
            : self::TYPE_MAP[$type];

        $name = (string) ($this->option('name')
            ?: strtolower($type).'-'.(gethostname() ?: 'host').'-'.getmypid());

        $agent = $agents->register($name, $type, $jobTypes, version: '1.0.0');

        $this->info("worker {$agent->worker_name} (#{$agent->id}, {$type}) registered — types: ".implode(',', $jobTypes));

        $this->registerSignalHandlers($runner);

        $processed = $runner->run(
            $agent,
            once: (bool) $this->option('once'),
            maxJobs: (int) $this->option('max-jobs'),
        );

        $this->info("worker {$agent->worker_name} stopped — processed {$processed} job(s)");

        return self::SUCCESS;
    }

    private function registerSignalHandlers(WorkerRunner $runner): void
    {
        if (! extension_loaded('pcntl')) {
            return; // Windows 등 — supervisor 미사용 환경
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $runner->requestShutdown()); // drain: 진행 중 job 완료 후 종료
        pcntl_signal(SIGINT, fn () => $runner->requestShutdown());
    }
}
