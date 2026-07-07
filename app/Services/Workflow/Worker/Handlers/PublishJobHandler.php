<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\ContentStatus;
use App\Enums\FailureType;
use App\Enums\InstanceStatus;
use App\Enums\JobStatus;
use App\Models\Content;
use App\Models\MediaRendition;
use App\Models\WorkflowJob;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\StateMachine\WorkflowInstanceStateMachine;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use Illuminate\Support\Facades\DB;

/**
 * PUBLISH — §8 판정을 단일 트랜잭션으로 수행: 필수 job 전체 SUCCESS + 필수 rendition
 * (DB 행 + 스토리지 파일 실재 stat 이중 확인) + contents PROCESSING→READY(CAS) + instance→SUCCESS.
 */
class PublishJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'PUBLISH';

    /** 유형별 웹 표출 필수 rendition — State Machine Spec §8 ③ (MVP: VIDEO) */
    private const array REQUIRED_RENDITIONS = [
        'VIDEO' => ['MASTER', 'PROXY_VIDEO', 'THUMBNAIL'],
    ];

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly ContentStateMachine $contents,
        private readonly WorkflowInstanceStateMachine $instances,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        return DB::transaction(function () use ($job, $ctx) {
            $content = Content::query()->whereKey($job->content_id)->lockForUpdate()->firstOrFail();
            $instance = $job->instance;

            // 재실행 멱등 — 이미 게시 완료면 성공 처리
            if ($content->status === ContentStatus::Ready
                && $instance->fresh()->status === InstanceStatus::Success) {
                return JobResult::success(['already_published' => true]);
            }

            if ($content->status !== ContentStatus::Processing) {
                return JobResult::failure(
                    FailureType::SystemError, 'CONTENT_NOT_PROCESSING',
                    "content status is {$content->status->value}", retryable: false,
                );
            }

            $blocking = WorkflowJob::query()
                ->where('instance_id', $job->instance_id)
                ->where('is_required', true)
                ->whereNotIn('job_type', ['PUBLISH', 'CLEANUP'])
                ->where('status', '<>', JobStatus::Success->value)
                ->count();

            if ($blocking > 0) {
                // Scheduler 게이트가 보장하므로 정상 흐름에선 발생하지 않는다 — 경합 방어
                return JobResult::failure(FailureType::SystemError, 'PUBLISH_BLOCKED', "{$blocking} required job(s) not SUCCESS");
            }

            $requiredTypes = self::REQUIRED_RENDITIONS[$content->media_type?->value] ?? null;

            if ($requiredTypes === null) {
                return JobResult::failure(
                    FailureType::SystemError, 'PUBLISH_BLOCKED',
                    "publish for media_type {$content->media_type?->value} is out of MVP scope", retryable: false,
                );
            }

            foreach ($requiredTypes as $type) {
                $rendition = MediaRendition::query()
                    ->where('content_id', $content->id)
                    ->where('rendition_type', $type)
                    ->first();

                // DB 행 + 스토리지 파일 실재(stat) 이중 확인 — 누락 시 재시도하지 않고
                // 해당 생성 job의 수동 재실행을 유도한다 (SM Spec §8·§19-6)
                if ($rendition === null
                    || ! is_file($this->storage->absolutePath($rendition->storage_zone, $rendition->path))) {
                    return JobResult::failure(
                        FailureType::StorageError, 'REQUIRED_RENDITION_MISSING',
                        "required rendition missing: {$type}", retryable: false,
                    );
                }
            }

            $ctx->progress->report($job->id, 80.0, 'publishing');

            $contentResult = $this->contents->transition(
                $content, ContentStatus::Ready->value, "worker:{$ctx->worker->id}", 'publish',
                ['published_at' => now()],
            );

            if (! $contentResult->ok) {
                return JobResult::failure(FailureType::SystemError, 'CONTENT_NOT_PROCESSING', 'content CAS conflict during publish');
            }

            $this->instances->transition(
                $instance, InstanceStatus::Success->value, "worker:{$ctx->worker->id}", 'publish',
                ['finished_at' => now()],
            );

            return JobResult::success(['published' => true]);
        });
    }
}
