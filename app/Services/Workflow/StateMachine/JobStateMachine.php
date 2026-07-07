<?php

namespace App\Services\Workflow\StateMachine;

use App\Enums\JobStatus;
use App\Models\WorkflowJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * workflow_jobs.status 전이 — State Machine Spec §5. 전이는 history 기록과 같은 트랜잭션.
 * DB trigger(check_job_transition)가 2차 방어선으로 동일 전이표를 검증한다.
 */
class JobStateMachine extends AbstractStateMachine
{
    protected function transitions(): array
    {
        return JobStatus::transitionMap();
    }

    protected function targetName(): string
    {
        return 'job';
    }

    protected function writeHistory(Model $model, string $from, string $to, string $actor, ?string $note): void
    {
        DB::table('workflow_job_histories')->insert([
            'job_id' => $model->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'actor' => $actor,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /** job 생성 이력 — from_status NULL (Spec §15) */
    public function recordCreation(WorkflowJob $job, string $actor, ?string $note = null): void
    {
        DB::table('workflow_job_histories')->insert([
            'job_id' => $job->id,
            'from_status' => null,
            'to_status' => $job->status->value,
            'actor' => $actor,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * Worker 완료 기록 전용 — lease fencing: CAS WHERE에 worker_id를 추가한다 (Spec §10·§16).
     * 죽었다 살아난 Worker의 늦은 결과는 0행 갱신 → conflict로 폐기된다.
     *
     * @param array<string, mixed> $extra
     */
    public function transitionOwnedBy(WorkflowJob $job, string $to, int $workerId, string $actor, ?string $note = null, array $extra = []): TransitionResult
    {
        $from = $job->status->value;
        $this->assertTransition($from, $to);
        $this->assertInTransaction();

        $updated = $this->casUpdate($job, $from, $to, $extra, ['worker_id' => $workerId]);
        if ($updated === 0) {
            return TransitionResult::conflict($job->fresh()->status->value);
        }

        $this->writeHistory($job, $from, $to, $actor, $note);

        return TransitionResult::ok($from, $to);
    }
}
