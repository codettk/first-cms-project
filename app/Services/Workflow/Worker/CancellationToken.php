<?php

namespace App\Services\Workflow\Worker;

use App\Exceptions\JobCanceledException;
use App\Models\WorkflowJob;
use App\Services\Workflow\Queue\JobLockService;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * 취소 신호 + lease 연장 폴링 — Worker Agent Spec §7·§14.
 * Handler는 실행 중 주기적으로 tick()을 호출한다: heartbeat 주기마다
 * lease 연장(상실 시 LeaseLostException) + cancel_requested_at 확인(JobCanceledException).
 */
class CancellationToken
{
    private float $lastTickAt = 0.0;

    public function __construct(
        private readonly WorkflowJob $job,
        private readonly int $workerId,
        private readonly JobLockService $locks,
        private readonly ?Closure $shutdownRequested = null,
    ) {}

    public function tick(bool $force = false): void
    {
        if (! $force
            && (microtime(true) - $this->lastTickAt) < JobLockService::HEARTBEAT_INTERVAL_SECONDS) {
            return;
        }

        $this->lastTickAt = microtime(true);

        $this->locks->extend($this->job->id, $this->workerId);

        if ($this->isCancelRequested()) {
            throw new JobCanceledException($this->job->id);
        }
    }

    public function isCancelRequested(): bool
    {
        if (($this->shutdownRequested)?->__invoke() === true) {
            return true;
        }

        return DB::table('workflow_jobs')
            ->where('id', $this->job->id)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }
}
