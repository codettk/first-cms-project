<?php

namespace App\Services\Workflow\Admin\Query;

use App\Enums\JobStatus;
use App\Models\Content;
use App\Models\User;
use App\Services\Workflow\Admin\AvailableActionService;
use App\Services\Workflow\Admin\Data\ListFilterData;
use App\Http\Resources\Admin\Concerns\MasksPaths;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 콘텐츠 조회 — Admin API Spec §7·§8 계약 형태로 반환. 상태 변경 금지.
 * Master는 path_masked만 — 전체 경로·직접 URL 금지.
 */
class ContentQueryService
{
    use MasksPaths;

    public const array ALLOWED_FILTERS = ['status', 'media_type', 'has_failed', 'q'];

    public const array ALLOWED_SORTS = ['created_at', 'updated_at', 'title'];

    public function __construct(private readonly AvailableActionService $actions) {}

    public function paginate(ListFilterData $filter, User $user): LengthAwarePaginator
    {
        $query = Content::query()
            ->with(['creator', 'searchIndexState'])
            ->when($filter->filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter->filters['media_type'] ?? null, fn ($q, $v) => $q->where('media_type', $v))
            ->when($filter->filters['q'] ?? null, fn ($q, $v) => $q->where('title', 'ilike', "%{$v}%"))
            ->when(
                filter_var($filter->filters['has_failed'] ?? false, FILTER_VALIDATE_BOOL),
                fn ($q) => $q->whereHas('jobs', fn ($j) => $j->where('status', 'FAILED'))
            )
            ->orderBy($filter->sort, $filter->direction);

        $paginator = $query->paginate($filter->perPage, page: $filter->page);

        // 파생 필드 일괄 로드 (N+1 방지)
        $contentIds = collect($paginator->items())->pluck('id');
        $jobs = DB::table('workflow_jobs')
            ->join('workflow_instances', 'workflow_instances.id', '=', 'workflow_jobs.instance_id')
            ->whereIn('workflow_jobs.content_id', $contentIds)
            ->select('workflow_jobs.*', 'workflow_instances.status as instance_status')
            ->orderBy('workflow_jobs.instance_id')
            ->get()
            ->groupBy('content_id');
        $progressRows = DB::table('workflow_job_progresses')
            ->whereIn('job_id', $jobs->flatten(1)->pluck('id'))
            ->get()->keyBy('job_id');
        $renditionTypes = DB::table('media_renditions')
            ->whereIn('content_id', $contentIds)
            ->select('content_id', 'rendition_type')
            ->get()->groupBy('content_id');

        return $paginator->through(function (Content $content) use ($user, $jobs, $progressRows, $renditionTypes) {
            $contentJobs = collect($jobs->get($content->id, collect()));
            $latestInstanceId = $contentJobs->max('instance_id');
            $currentJobs = $contentJobs->where('instance_id', $latestInstanceId);
            $required = $currentJobs->where('is_required', true)
                ->whereNotIn('job_type', ['PUBLISH', 'CLEANUP']);
            $running = $currentJobs->firstWhere('status', 'RUNNING');
            $types = collect($renditionTypes->get($content->id, collect()))->pluck('rendition_type');

            return [
                'content_id' => $content->id,
                'uuid' => $content->uuid,
                'title' => $content->title,
                'media_type' => $content->media_type?->value,
                'content_status' => $content->status->value,
                'current_stage' => $running !== null ? [
                    'job_type' => $running->job_type,
                    'status' => $running->status,
                    'progress_percent' => (float) ($progressRows->get($running->id)?->progress_percent ?? 0),
                ] : null,
                'progress' => [
                    'done_required' => $required->whereIn('status', ['SUCCESS'])->count(),
                    'total_required' => $required->count(),
                ],
                'has_failed_job' => $contentJobs->contains('status', 'FAILED'),
                'has_proxy' => $types->intersect(['PROXY_VIDEO', 'PROXY_IMAGE', 'PROXY_AUDIO'])->isNotEmpty(),
                'has_thumbnail' => $types->contains('THUMBNAIL'),
                'index_status' => $content->searchIndexState?->status->value,
                'publish_status' => $currentJobs->firstWhere('job_type', 'PUBLISH')?->status,
                'uploaded_by' => $content->creator === null ? null : [
                    'id' => $content->creator->id, 'name' => $content->creator->name,
                ],
                'created_at' => $content->created_at?->toIso8601String(),
                'updated_at' => $content->updated_at?->toIso8601String(),
                'available_actions' => $this->actions->forContent($content, $user),
            ];
        });
    }

    /** @return array<string, mixed> Admin API Spec §8 WorkflowContentDetail */
    public function detail(Content $content, User $user): array
    {
        $content->load(['mediaFiles', 'renditions', 'searchIndexState']);

        $instance = $content->instances()->with('template')->latest('id')->first();
        $jobs = $instance?->jobs()->with('worker')->orderBy('id')->get() ?? collect();
        $progressRows = DB::table('workflow_job_progresses')
            ->whereIn('job_id', $jobs->pluck('id'))->get()->keyBy('job_id');

        $master = $content->renditions->first(fn ($r) => $r->rendition_type->value === 'MASTER');

        $renditionGroup = fn (array $types) => $content->renditions
            ->filter(fn ($r) => in_array($r->rendition_type->value, $types, true))
            ->values()
            ->map(fn ($r) => [
                'rendition_type' => $r->rendition_type->value,
                'variant_key' => $r->variant_key,
                'width' => $r->width,
                'height' => $r->height,
                'duration_ms' => $r->duration_ms !== null ? (int) $r->duration_ms : null,
                'page_no' => $r->page_no,
                'timecode_ms' => $r->timecode_ms !== null ? (int) $r->timecode_ms : null,
                // 비-MASTER rendition만 preview URL 생성 (MVP: zone 상대 경로 기반)
                'preview_url' => "/admin/workflows/preview/{$r->id}",
            ])->all();

        $recentHistories = DB::table('workflow_job_histories')
            ->whereIn('job_id', $jobs->pluck('id'))
            ->orderByDesc('created_at')->limit(20)->get()
            ->map(fn ($h) => [
                'from' => $h->from_status, 'to' => $h->to_status,
                'actor' => $h->actor, 'note' => $h->note, 'at' => $h->created_at,
            ])->all();

        $recentLogs = DB::table('workflow_job_logs')
            ->whereIn('job_id', $jobs->pluck('id'))
            ->orderByDesc('created_at')->limit(20)->get()
            ->map(fn ($l) => [
                'job_id' => $l->job_id, 'level' => $l->level,
                'message' => $l->message, 'at' => $l->created_at,
            ])->all();

        return [
            'content' => [
                'content_id' => $content->id,
                'uuid' => $content->uuid,
                'title' => $content->title,
                'media_type' => $content->media_type?->value,
                'status' => $content->status->value,
                'created_at' => $content->created_at?->toIso8601String(),
                'updated_at' => $content->updated_at?->toIso8601String(),
            ],
            'media_files' => $content->mediaFiles->map(fn ($f) => [
                'id' => $f->id,
                'original_filename' => $f->original_filename,
                'file_size' => (int) $f->file_size,
                'checksum' => $f->checksum,
                'detected_mime' => $f->detected_mime,
            ])->all(),
            'renditions' => [
                'master' => [
                    'exists' => $master !== null,
                    'storage_zone' => $master?->storage_zone->value,
                    'path_masked' => $master !== null ? self::maskPath($master->path) : null,
                    'file_size' => $master?->file_size !== null ? (int) $master->file_size : null,
                ],
                'proxy' => $renditionGroup(['PROXY_VIDEO', 'PROXY_IMAGE', 'PROXY_AUDIO']),
                'thumbnail' => $renditionGroup(['THUMBNAIL']),
                'catalog' => $renditionGroup(['CATALOG']),
                'document_preview' => $renditionGroup(['PAGE_PREVIEW', 'DOCUMENT_PREVIEW']),
            ],
            'search_index' => [
                'status' => $content->searchIndexState?->status->value,
                'index_version' => (int) ($content->searchIndexState?->index_version ?? 0),
                'indexed_at' => $content->searchIndexState?->indexed_at?->toIso8601String(),
            ],
            'workflow_instance' => $instance === null ? null : [
                'id' => $instance->id,
                'template_code' => $instance->template?->code,
                'template_version' => (int) ($instance->template?->version ?? 1),
                'status' => $instance->status->value,
                'started_at' => $instance->started_at?->toIso8601String(),
            ],
            'job_pipeline' => $jobs->map(fn ($j) => [
                'job_id' => $j->id,
                'job_type' => $j->job_type->value,
                'status' => $j->status->value,
                'started_at' => $j->started_at?->toIso8601String(),
                'finished_at' => $j->finished_at?->toIso8601String(),
                'elapsed_sec' => $j->started_at !== null
                    ? (int) ($j->finished_at ?? now())->diffInSeconds($j->started_at, true)
                    : null,
                'worker_name' => $j->worker?->worker_name,
                'retry_count' => $j->retry_count,
                'progress_percent' => $j->status === JobStatus::Running
                    ? (float) ($progressRows->get($j->id)?->progress_percent ?? 0)
                    : null,
                'fail_reason_code' => $j->fail_reason_code,
            ])->all(),
            'recent_histories' => $recentHistories,
            'recent_logs' => $recentLogs,
            'available_actions' => $this->actions->forContent($content, $user),
        ];
    }
}
