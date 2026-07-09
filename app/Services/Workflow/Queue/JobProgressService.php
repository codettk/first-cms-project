<?php

namespace App\Services\Workflow\Queue;

use Illuminate\Support\Facades\DB;

/**
 * 진행률 upsert — Queue Worker Spec §10 · Worker Agent Spec §11.
 * 진행률은 workflow_job_progresses에만 기록한다(workflow_jobs 저장 금지).
 * 스로틀: 마지막 갱신에서 2초 경과 또는 1%p 변화 시에만 UPDATE.
 */
class JobProgressService
{
    private const int THROTTLE_SECONDS = 2;

    private const float THROTTLE_PERCENT_DELTA = 1.0;

    /** @var array<int, array{at: float, percent: float}> */
    private array $lastReported = [];

    public function report(int $jobId, float $percent, ?string $message = null, ?int $estimatedRemainingSec = null): bool
    {
        $percent = max(0.0, min(100.0, $percent));

        $last = $this->lastReported[$jobId] ?? null;
        if ($last !== null
            && (microtime(true) - $last['at']) < self::THROTTLE_SECONDS
            && abs($percent - $last['percent']) < self::THROTTLE_PERCENT_DELTA) {
            return false;
        }

        DB::statement(
            'INSERT INTO workflow_job_progresses
                (job_id, progress_percent, progress_message, estimated_remaining_sec, updated_at)
             VALUES (?, ?, ?, ?, now())
             ON CONFLICT (job_id) DO UPDATE SET
                progress_percent = EXCLUDED.progress_percent,
                progress_message = EXCLUDED.progress_message,
                estimated_remaining_sec = EXCLUDED.estimated_remaining_sec,
                updated_at = now()',
            [$jobId, $percent, $message, $estimatedRemainingSec]
        );

        $this->lastReported[$jobId] = ['at' => microtime(true), 'percent' => $percent];

        return true;
    }

    /** 종결 시 행 삭제 — 100% 유지 대신 삭제가 표준 (RUNNING 화면 전용 데이터) */
    public function clear(int $jobId): void
    {
        DB::table('workflow_job_progresses')->where('job_id', $jobId)->delete();
        unset($this->lastReported[$jobId]);
    }
}
