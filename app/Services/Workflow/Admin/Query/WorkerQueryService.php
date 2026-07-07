<?php

namespace App\Services\Workflow\Admin\Query;

use App\Models\User;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Admin\AvailableActionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Worker 조회 — Admin API Spec §17 계약 형태. heartbeat_delay_sec = now() − last_heartbeat_at.
 */
class WorkerQueryService
{
    public function __construct(private readonly AvailableActionService $actions) {}

    /** @return Collection<int, array<string, mixed>> */
    public function list(User $user): Collection
    {
        $currentJobs = DB::table('workflow_jobs')
            ->whereIn('id', WorkflowWorkerAgent::query()->whereNotNull('current_job_id')->pluck('current_job_id'))
            ->get()->keyBy('id');

        return WorkflowWorkerAgent::query()->orderBy('worker_name')->get()
            ->map(function (WorkflowWorkerAgent $worker) use ($user, $currentJobs) {
                $currentJob = $worker->current_job_id !== null
                    ? $currentJobs->get($worker->current_job_id)
                    : null;

                return [
                    'worker_id' => $worker->id,
                    'worker_name' => $worker->worker_name,
                    'worker_type' => $worker->worker_type,
                    'status' => $worker->status->value,
                    'supported_job_types' => $worker->supported_job_types,
                    'hostname' => $worker->hostname,
                    'ip_address' => $worker->ip_address,
                    'last_heartbeat_at' => $worker->last_heartbeat_at?->toIso8601String(),
                    'heartbeat_delay_sec' => $worker->last_heartbeat_at !== null
                        ? (int) now()->diffInSeconds($worker->last_heartbeat_at, true)
                        : null,
                    'current_job' => $currentJob === null ? null : [
                        'job_id' => $currentJob->id,
                        'job_type' => $currentJob->job_type,
                        'content_id' => $currentJob->content_id,
                    ],
                    'max_concurrency' => $worker->max_concurrency,
                    'running_count' => $worker->current_job_id !== null ? 1 : 0, // 1proc=1job 전제
                    'gpu_available' => $worker->gpu_available,
                    'cpu_count' => $worker->cpu_count,
                    'memory_limit_mb' => $worker->memory_limit_mb,
                    'version' => $worker->version,
                    'available_actions' => $this->actions->forWorker($worker, $user),
                ];
            });
    }
}
