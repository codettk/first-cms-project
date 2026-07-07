<?php

namespace App\Services\Workflow\Queue;

use App\Enums\JobStatus;
use App\Enums\WorkerStatus;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\StateMachine\JobStateMachine;
use App\Services\Workflow\StateMachine\WorkerStateMachine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Job Claim — Queue Worker Spec §5 · Worker Agent Spec §6.
 *
 * Claim 트랜잭션이 최상위 소유자다. READY 상태만 소비한다(ADR-0003 —
 * RETRY 재개·retry_count 증가는 Scheduler 담당). 이중 lease는
 * workflow_job_locks PK가 DB 레벨에서 차단하며, 충돌 시 롤백 후 null(정상 흐름).
 */
class JobClaimService
{
    public function __construct(
        private readonly JobStateMachine $jobStateMachine,
        private readonly WorkerStateMachine $workerStateMachine,
    ) {}

    /**
     * @param list<string>|null $types null이면 worker의 supported_job_types 사용
     */
    public function tryClaim(WorkflowWorkerAgent $worker, ?array $types = null): ?WorkflowJob
    {
        $types ??= $worker->supported_job_types;

        try {
            return DB::transaction(function () use ($worker, $types) {
                $row = DB::selectOne(
                    "SELECT id FROM workflow_jobs
                     WHERE status = 'READY'
                       AND (next_attempt_at IS NULL OR next_attempt_at <= now())
                       AND job_type = ANY(?)
                     ORDER BY priority DESC, created_at ASC
                     LIMIT 1
                     FOR UPDATE SKIP LOCKED",
                    ['{'.implode(',', $types).'}']
                );

                if ($row === null) {
                    return null;
                }

                DB::table('workflow_job_locks')->insert([
                    'job_id' => $row->id,
                    'locked_by_worker_id' => $worker->id,
                    'locked_at' => now(),
                    'locked_until' => now()->addSeconds(JobLockService::LEASE_SECONDS),
                    'heartbeat_at' => now(),
                ]);

                $job = WorkflowJob::query()->findOrFail($row->id);

                $transition = $this->jobStateMachine->transition(
                    $job,
                    JobStatus::Running->value,
                    "worker:{$worker->id}",
                    'claimed',
                    ['worker_id' => $worker->id, 'started_at' => now()],
                );

                if (! $transition->ok) {
                    // FOR UPDATE로 행을 쥔 상태라 발생할 수 없다 — 방어적 롤백
                    throw new RuntimeException("claim CAS failed for job {$row->id}");
                }

                $worker->refresh();
                if ($worker->status === WorkerStatus::Online) {
                    $this->workerStateMachine->transition(
                        $worker,
                        WorkerStatus::Busy->value,
                        "worker:{$worker->id}",
                        null,
                        ['current_job_id' => $job->id],
                    );
                }

                return $job->refresh();
            });
        } catch (QueryException) {
            // locks PK 충돌 등 — 경합의 정상 흐름, 다음 폴링에서 재시도
            return null;
        }
    }
}
