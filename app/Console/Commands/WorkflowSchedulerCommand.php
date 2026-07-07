<?php

namespace App\Console\Commands;

use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use Illuminate\Console\Command;

/**
 * workflow:scheduler — Queue Worker Spec §8.
 * supervisor 관리 long-running 데몬(내부 10초 루프). advisory lock으로 단일 실행 보장.
 */
class WorkflowSchedulerCommand extends Command
{
    protected $signature = 'workflow:scheduler
        {--once : 틱 1회만 실행하고 종료 (테스트·CI용)}
        {--interval=10 : 틱 간격(초)}';

    protected $description = 'Workflow Scheduler 데몬 — READY 전환·SKIPPED 전파·PUBLISH 판정·RETRY 재개·타임아웃·Lock 회수·Worker 헬스';

    private bool $shuttingDown = false;

    public function handle(WorkflowSchedulerService $scheduler): int
    {
        if (! $scheduler->tryAcquireAdvisoryLock()) {
            $this->error('다른 Scheduler 인스턴스가 실행 중입니다 (advisory lock 획득 실패).');

            return self::FAILURE;
        }

        $this->registerSignalHandlers();

        try {
            do {
                $counts = $scheduler->tick();
                $this->line(now()->toIso8601String().' tick '.json_encode($counts));

                if ($this->option('once') || $this->shuttingDown) {
                    break;
                }

                sleep((int) $this->option('interval'));
            } while (! $this->shuttingDown);
        } finally {
            $scheduler->releaseAdvisoryLock();
        }

        return self::SUCCESS;
    }

    private function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return; // Windows 등 — supervisor 미사용 환경에서는 콘솔 중단으로 종료
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shuttingDown = true);
        pcntl_signal(SIGINT, fn () => $this->shuttingDown = true);
    }
}
