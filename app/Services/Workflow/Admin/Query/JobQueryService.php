<?php

namespace App\Services\Workflow\Admin\Query;

use App\Enums\JobStatus;
use App\Http\Resources\Admin\Concerns\MasksPaths;
use App\Models\User;
use App\Models\WorkflowJob;
use App\Services\Workflow\Admin\AvailableActionService;
use App\Services\Workflow\Admin\Data\ListFilterData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Job 조회 — Admin API Spec §11·§12 계약 형태. timeout_imminent = 경과 > timeout_sec × 0.8.
 * payload/result 내 master 경로는 마스킹, stdout/stderr는 URL만 (Controller Service Spec §12).
 */
class JobQueryService
{
    use MasksPaths;

    public const array ALLOWED_FILTERS = ['status', 'job_type', 'content_id', 'fail_reason_code', 'worker_id', 'is_required'];

    public const array ALLOWED_SORTS = ['created_at', 'priority', 'started_at', 'finished_at'];

    public function __construct(private readonly AvailableActionService $actions) {}

    public function paginate(ListFilterData $filter, User $user): LengthAwarePaginator
    {
        $query = WorkflowJob::query()
            ->with(['content:id,title', 'worker', 'lock', 'progress'])
            ->when($filter->filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter->filters['job_type'] ?? null, fn ($q, $v) => $q->where('job_type', $v))
            ->when($filter->filters['content_id'] ?? null, fn ($q, $v) => $q->where('content_id', (int) $v))
            ->when($filter->filters['fail_reason_code'] ?? null, fn ($q, $v) => $q->where('fail_reason_code', $v))
            ->when($filter->filters['worker_id'] ?? null, fn ($q, $v) => $q->where('worker_id', (int) $v))
            ->when(
                array_key_exists('is_required', $filter->filters),
                fn ($q) => $q->where('is_required', filter_var($filter->filters['is_required'], FILTER_VALIDATE_BOOL))
            )
            ->orderBy($filter->sort, $filter->direction);

        return $query->paginate($filter->perPage, page: $filter->page)
            ->through(fn (WorkflowJob $job) => $this->listItem($job, $user));
    }

    /** @return array<string, mixed> */
    private function listItem(WorkflowJob $job, User $user): array
    {
        $elapsed = $job->started_at !== null
            ? (int) ($job->finished_at ?? now())->diffInSeconds($job->started_at, true)
            : null;

        return [
            'job_id' => $job->id,
            'content_id' => $job->content_id,
            'content_title' => $job->content?->title,
            'job_type' => $job->job_type->value,
            'status' => $job->status->value,
            'is_required' => $job->is_required,
            'priority' => $job->priority,
            'retry_count' => $job->retry_count,
            'max_retry' => $job->max_retry,
            'next_attempt_at' => $job->next_attempt_at?->toIso8601String(),
            'worker' => $job->worker === null ? null : [
                'id' => $job->worker->id,
                'name' => $job->worker->worker_name,
                'type' => $job->worker->worker_type,
                'status' => $job->worker->status->value,
            ],
            'progress' => $job->progress === null ? null : [
                'percent' => (float) $job->progress->progress_percent,
                'message' => $job->progress->progress_message,
                'estimated_remaining_sec' => $job->progress->estimated_remaining_sec,
                'updated_at' => $job->progress->updated_at,
            ],
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'elapsed_sec' => $elapsed,
            'timeout_sec' => $job->timeout_sec,
            'timeout_imminent' => $job->status === JobStatus::Running
                && $elapsed !== null && $elapsed > $job->timeout_sec * 0.8,
            'locked_until' => $job->lock?->locked_until?->toIso8601String(),
            'heartbeat_at' => $job->lock?->heartbeat_at?->toIso8601String(),
            'fail_reason_code' => $job->fail_reason_code,
            'fail_message' => null, // 상세의 attempts/logs에서 제공
            'available_actions' => $this->actions->forJob($job, $user),
        ];
    }

    /** @return array<string, mixed> Admin API Spec §12 WorkflowJobDetail */
    public function detail(WorkflowJob $job, User $user): array
    {
        $job->load(['content', 'worker', 'lock', 'progress', 'instance.template']);

        $histories = DB::table('workflow_job_histories')
            ->where('job_id', $job->id)->orderBy('created_at')->get()
            ->map(fn ($h) => [
                'from' => $h->from_status, 'to' => $h->to_status,
                'actor' => $h->actor, 'note' => $h->note, 'at' => $h->created_at,
            ])->all();

        $attempts = DB::table('workflow_job_logs')
            ->where('job_id', $job->id)->orderBy('created_at')->get()
            ->groupBy('attempt_no')
            ->map(fn ($logs, $attemptNo) => [
                'attempt_no' => (int) $attemptNo,
                'logs' => $logs->map(fn ($l) => [
                    'level' => $l->level,
                    'command' => $l->command !== null ? self::maskPath($l->command) : null,
                    'message' => $l->message,
                    'stdout_url' => $l->stdout !== null ? "/admin/workflows/jobs/{$job->id}/logs/{$l->id}/stdout" : null,
                    'stderr_url' => $l->stderr !== null ? "/admin/workflows/jobs/{$job->id}/logs/{$l->id}/stderr" : null,
                    'detail' => $l->detail !== null ? json_decode($l->detail, true) : null,
                    'at' => $l->created_at,
                ])->values()->all(),
            ])->values()->all();

        $renditions = DB::table('media_renditions')
            ->where('content_id', $job->content_id)
            ->orderBy('id')->get()
            ->map(fn ($r) => [
                'rendition_type' => $r->rendition_type,
                'variant_key' => $r->variant_key,
                'width' => $r->width,
                'height' => $r->height,
                'duration_ms' => $r->duration_ms !== null ? (int) $r->duration_ms : null,
                'page_no' => $r->page_no,
                'timecode_ms' => $r->timecode_ms !== null ? (int) $r->timecode_ms : null,
                'preview_url' => $r->rendition_type === 'MASTER' ? null : "/admin/workflows/preview/{$r->id}",
            ])->all();

        return [
            'job' => [
                'job_id' => $job->id,
                'job_type' => $job->job_type->value,
                'status' => $job->status->value,
                'is_required' => $job->is_required,
                'retry_count' => $job->retry_count,
                'max_retry' => $job->max_retry,
                'priority' => $job->priority,
                'timeout_sec' => $job->timeout_sec,
            ],
            'content' => [
                'content_id' => $job->content_id,
                'title' => $job->content?->title,
                'status' => $job->content?->status->value,
            ],
            'instance' => [
                'id' => $job->instance_id,
                'template_code' => $job->instance?->template?->code,
                'version' => (int) ($job->instance?->template?->version ?? 1),
            ],
            'worker' => $job->worker === null ? null : [
                'id' => $job->worker->id,
                'name' => $job->worker->worker_name,
                'type' => $job->worker->worker_type,
                'status' => $job->worker->status->value,
            ],
            'payload' => self::maskArrayPaths($job->payload),
            'result' => self::maskArrayPaths($job->result),
            'histories' => $histories,
            'attempts' => $attempts,
            'progress' => $job->progress === null ? null : [
                'percent' => (float) $job->progress->progress_percent,
                'message' => $job->progress->progress_message,
                'estimated_remaining_sec' => $job->progress->estimated_remaining_sec,
                'updated_at' => $job->progress->updated_at,
            ],
            'lock' => $job->lock === null ? null : [
                'locked_by_worker_id' => $job->lock->locked_by_worker_id,
                'locked_at' => $job->lock->locked_at?->toIso8601String(),
                'locked_until' => $job->lock->locked_until?->toIso8601String(),
                'heartbeat_at' => $job->lock->heartbeat_at?->toIso8601String(),
            ],
            'generated_renditions' => $renditions,
            'available_actions' => $this->actions->forJob($job, $user),
        ];
    }
}
