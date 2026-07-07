<?php

namespace App\Services\Workflow\Worker;

use App\Enums\WorkerStatus;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\StateMachine\WorkerStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Worker 등록·heartbeat·상태 관리 — Worker Agent Spec §4 · Queue Worker Spec §16.
 * 등록은 worker_name 기준 upsert. 상태 전이는 WorkerStateMachine 경유.
 */
class WorkerAgentService
{
    public function __construct(private readonly WorkerStateMachine $stateMachine) {}

    /**
     * @param list<string> $supportedJobTypes
     */
    public function register(
        string $workerName,
        string $workerType,
        array $supportedJobTypes,
        ?string $version = null,
    ): WorkflowWorkerAgent {
        $agent = WorkflowWorkerAgent::query()->where('worker_name', $workerName)->first();

        $attributes = [
            'worker_type' => $workerType,
            'supported_job_types' => $supportedJobTypes,
            'hostname' => gethostname() ?: 'unknown',
            'ip_address' => gethostbyname(gethostname() ?: 'localhost'),
            'max_concurrency' => 1, // 초기 구현: Worker 프로세스 1개 = Job 1개 (Revision Checklist §7)
            'version' => $version,
            'last_heartbeat_at' => now(),
        ];

        if ($agent === null) {
            $agent = WorkflowWorkerAgent::create([
                ...$attributes,
                'worker_name' => $workerName,
                'status' => WorkerStatus::Offline,
            ]);
        } else {
            $agent->fill($attributes)->save();
        }

        // OFFLINE → ONLINE 전이 (부팅·재개). DISABLED/ERROR는 운영자 해제 전까지 유지.
        if ($agent->status === WorkerStatus::Offline) {
            DB::transaction(fn () => $this->stateMachine->transition(
                $agent, WorkerStatus::Online->value, "worker:{$agent->id}", 'boot'
            ));
            $agent->refresh();
        }

        return $agent;
    }

    public function heartbeat(WorkflowWorkerAgent $agent): void
    {
        DB::table('workflow_worker_agents')
            ->where('id', $agent->id)
            ->update(['last_heartbeat_at' => now()]);
    }

    /** job 종료 후 슬롯 반환 — BUSY→ONLINE + current_job_id 정리 */
    public function clearCurrentJob(WorkflowWorkerAgent $agent): void
    {
        $agent->refresh();

        if ($agent->status === WorkerStatus::Busy) {
            DB::transaction(fn () => $this->stateMachine->transition(
                $agent, WorkerStatus::Online->value, "worker:{$agent->id}", null,
                ['current_job_id' => null],
            ));

            return;
        }

        DB::table('workflow_worker_agents')
            ->where('id', $agent->id)
            ->update(['current_job_id' => null]);
    }

    /** graceful shutdown — 종료 직전 OFFLINE 전환 */
    public function markOffline(WorkflowWorkerAgent $agent): void
    {
        $agent->refresh();

        if (in_array($agent->status, [WorkerStatus::Online, WorkerStatus::Busy], true)) {
            DB::transaction(fn () => $this->stateMachine->transition(
                $agent, WorkerStatus::Offline->value, "worker:{$agent->id}", 'shutdown',
                ['current_job_id' => null],
            ));
        }
    }
}
