<?php

namespace App\Services\Workflow\Queue;

use App\Exceptions\LeaseLostException;
use Illuminate\Support\Facades\DB;

/**
 * Lock lease 연장·해제 — Queue Worker Spec §6 · Worker Agent Spec §7.
 * 연장 0행 = lease 상실(Scheduler 회수·DB 단절). 2회 연속이면 실행 중단(LeaseLostException).
 */
class JobLockService
{
    public const int LEASE_SECONDS = 600;

    public const int HEARTBEAT_INTERVAL_SECONDS = 30;

    private int $consecutiveFailures = 0;

    /**
     * lease 연장 + worker heartbeat 갱신. 자기 소유 lock만 연장된다.
     */
    public function extend(int $jobId, int $workerId): bool
    {
        $rows = DB::update(
            'UPDATE workflow_job_locks
             SET heartbeat_at = now(), locked_until = now() + make_interval(secs => ?)
             WHERE job_id = ? AND locked_by_worker_id = ?',
            [self::LEASE_SECONDS, $jobId, $workerId]
        );

        DB::table('workflow_worker_agents')
            ->where('id', $workerId)
            ->update(['last_heartbeat_at' => now()]);

        if ($rows === 0) {
            $this->consecutiveFailures++;
            if ($this->consecutiveFailures >= 2) {
                $this->consecutiveFailures = 0;
                throw new LeaseLostException($jobId);
            }

            return false;
        }

        $this->consecutiveFailures = 0;

        return true;
    }

    /** lock 해제 — 자기 소유만 삭제 */
    public function release(int $jobId, int $workerId): void
    {
        DB::table('workflow_job_locks')
            ->where('job_id', $jobId)
            ->where('locked_by_worker_id', $workerId)
            ->delete();

        $this->consecutiveFailures = 0;
    }
}
