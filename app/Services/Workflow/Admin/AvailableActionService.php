<?php

namespace App\Services\Workflow\Admin;

use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Enums\WorkerStatus;
use App\Models\Content;
use App\Models\User;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;

/**
 * 상태 × 권한 → available_actions 단일 계산 지점 — Controller Service Spec §8.
 * 프론트는 이 배열만 사용한다 (자체 상태 판정 금지 — Revision Checklist §5-④).
 */
class AvailableActionService
{
    /** @return list<string> */
    public function forContent(Content $content, User $user): array
    {
        $actions = [];

        if ($user->can('workflow.view')) {
            $actions[] = 'view_detail';
        }
        if ($user->can('workflow.logs.view')) {
            $actions[] = 'view_logs';
        }
        if (in_array($content->status, [ContentStatus::Ready, ContentStatus::Failed], true)
            && $user->can('workflow.reprocess')) {
            $actions[] = 'reprocess';
        }
        if ($content->status === ContentStatus::Processing && $user->can('workflow.cancel')) {
            $actions[] = 'cancel';
        }
        if ($user->can('workflow.retry')
            && $content->jobs()->where('job_type', 'PUBLISH')->where('status', JobStatus::Failed->value)->exists()) {
            $actions[] = 'publish_retry';
        }

        return $actions;
    }

    /** @return list<string> */
    public function forJob(WorkflowJob $job, User $user): array
    {
        $actions = [];

        if ($user->can('workflow.logs.view')) {
            $actions[] = 'view_logs';
        }
        if ($user->can('workflow.view')) {
            $actions[] = 'view_content';
        }
        if ($job->status === JobStatus::Failed && $user->can('workflow.retry')) {
            $actions[] = 'retry';
        }
        if (in_array($job->status, [JobStatus::Waiting, JobStatus::Ready, JobStatus::Running], true)
            && $user->can('workflow.cancel')) {
            $actions[] = 'cancel';
        }
        if ($job->status === JobStatus::Running && $job->lock()->exists()
            && $user->can('workflow.release_lock')) {
            $actions[] = 'release_lock';
        }
        if (in_array($job->status, [JobStatus::Waiting, JobStatus::Ready, JobStatus::Retry], true)
            && $user->can('workflow.retry')) {
            $actions[] = 'update_priority';
        }

        return $actions;
    }

    /** @return list<string> */
    public function forWorker(WorkflowWorkerAgent $worker, User $user): array
    {
        $actions = [];

        if (in_array($worker->status, [WorkerStatus::Online, WorkerStatus::Busy], true)
            && $user->can('workflow.worker.manage')) {
            $actions[] = 'disable';
        }
        if ($worker->status === WorkerStatus::Disabled && $user->can('workflow.worker.manage')) {
            $actions[] = 'enable';
        }
        if ($worker->status === WorkerStatus::Error && $user->can('workflow.worker.manage')) {
            $actions[] = 'clear_error';
        }
        if ($worker->current_job_id !== null && $user->can('workflow.view')) {
            $actions[] = 'view_current_job';
        }
        if ($user->can('workflow.logs.view')) {
            $actions[] = 'view_logs';
        }

        return $actions;
    }
}
