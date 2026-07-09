<?php

namespace App\Services\Workflow\Admin\Command;

use App\Enums\ContentStatus;
use App\Enums\InstanceStatus;
use App\Enums\JobStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Content;
use App\Models\User;
use App\Services\Workflow\Admin\AuditLogger;
use App\Services\Workflow\Admin\AvailableActionService;
use App\Services\Workflow\Admin\Data\CommandResult;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\StateMachine\JobStateMachine;
use App\Services\Workflow\StateMachine\WorkflowInstanceStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * 콘텐츠 취소 — 비종결 job 일괄 CANCELED(각 history) + RUNNING은 cancel_requested_at 마킹 +
 * instance CANCELED + content FAILED(note=canceled) — 단일 트랜잭션 (Controller Service Spec §10·§16).
 */
class ContentCancelService
{
    public function __construct(
        private readonly ContentStateMachine $contents,
        private readonly WorkflowInstanceStateMachine $instances,
        private readonly JobStateMachine $jobs,
        private readonly AuditLogger $audit,
        private readonly AvailableActionService $actions,
    ) {}

    public function execute(Content $content, string $reason, User $actor): CommandResult
    {
        return DB::transaction(function () use ($content, $reason, $actor) {
            $content = Content::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $before = $content->status->value;

            if ($content->status !== ContentStatus::Processing) {
                throw new InvalidStateTransitionException(
                    'content', $before, 'FAILED', currentState: $before,
                    allowedActions: $this->actions->forContent($content, $actor),
                );
            }

            $instance = $content->instances()->where('status', InstanceStatus::Running->value)->first();
            $canceled = 0;
            $cancelRequested = 0;

            if ($instance !== null) {
                foreach ($instance->jobs()->get() as $job) {
                    if (in_array($job->status, [JobStatus::Waiting, JobStatus::Ready, JobStatus::Retry], true)) {
                        $this->jobs->transition(
                            $job, JobStatus::Canceled->value, "admin:{$actor->id}",
                            'content_canceled', ['finished_at' => now()],
                        );
                        $canceled++;
                    } elseif ($job->status === JobStatus::Running) {
                        // Worker가 heartbeat 주기에 감지 → cancel() → CANCELED 전이 (SM Spec §11)
                        DB::table('workflow_jobs')->where('id', $job->id)
                            ->update(['cancel_requested_at' => now()]);
                        $cancelRequested++;
                    }
                }

                $this->instances->transition(
                    $instance, InstanceStatus::Canceled->value, "admin:{$actor->id}",
                    $reason, ['finished_at' => now()],
                );
            }

            $this->contents->transition(
                $content, ContentStatus::Failed->value, "admin:{$actor->id}", 'canceled'
            );

            $this->audit->log('content.cancel', $content, $before, 'FAILED', [
                'jobs_canceled' => $canceled, 'cancel_requested' => $cancelRequested,
            ], $reason);

            return CommandResult::ok(
                [
                    'content_id' => $content->id, 'status' => 'FAILED',
                    'jobs_canceled' => $canceled, 'cancel_requested' => $cancelRequested,
                ],
                $this->actions->forContent($content->refresh(), $actor),
            );
        });
    }
}
