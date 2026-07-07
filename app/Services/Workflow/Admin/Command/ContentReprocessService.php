<?php

namespace App\Services\Workflow\Admin\Command;

use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Content;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\Admin\AuditLogger;
use App\Services\Workflow\Admin\AvailableActionService;
use App\Services\Workflow\Admin\Data\CommandResult;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 콘텐츠 재처리 — content CAS(READY|FAILED→PROCESSING) + 새 instance/jobs 생성 원자적
 * (Controller Service Spec §10·§16). failed_from은 직전 성공 단계를 SUCCESS로 재사용한다.
 */
class ContentReprocessService
{
    public function __construct(
        private readonly ContentStateMachine $contents,
        private readonly WorkflowInstanceFactory $factory,
        private readonly AuditLogger $audit,
        private readonly AvailableActionService $actions,
    ) {}

    public function execute(Content $content, string $mode, string $reason, ?int $priority, User $actor): CommandResult
    {
        return DB::transaction(function () use ($content, $mode, $reason, $priority, $actor) {
            $content = Content::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $before = $content->status->value;

            if ($content->media_type === null) {
                throw ValidationException::withMessages([
                    'content' => 'media_type 미확정 콘텐츠는 재처리할 수 없습니다 (MA 미완료).',
                ]);
            }

            $template = WorkflowTemplate::query()
                ->where('media_type', $content->media_type->value)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->firstOrFail();

            $reuseSuccessTypes = $mode === 'failed_from'
                ? $this->previousSuccessTypes($content)
                : [];

            $transition = $this->contents->transition(
                $content, ContentStatus::Processing->value, "admin:{$actor->id}", $reason
            );

            if (! $transition->ok) {
                throw new InvalidStateTransitionException(
                    'content', $before, 'PROCESSING',
                    currentState: $transition->conflictCurrentStatus,
                );
            }

            $instance = $this->factory->createForContent(
                $content->refresh(), $template,
                priority: $priority ?? 200, // 관리자 수동 재처리 tier (Queue Spec §12)
                actor: "admin:{$actor->id}",
                reuseSuccessTypes: $reuseSuccessTypes,
            );

            $this->audit->log('content.reprocess', $content, $before, 'PROCESSING', [
                'mode' => $mode, 'priority' => $priority, 'instance_id' => $instance->id,
                'reused_steps' => $reuseSuccessTypes,
            ], $reason);

            return CommandResult::ok(
                ['content_id' => $content->id, 'status' => 'PROCESSING', 'instance_id' => $instance->id],
                $this->actions->forContent($content->refresh(), $actor),
            );
        });
    }

    /**
     * 직전 종결 인스턴스에서 SUCCESS였던 job_type — failed_from 모드에서 SUCCESS로 재사용
     * (산출물 rendition은 이미 존재 — 재실행 불필요).
     *
     * @return list<string>
     */
    private function previousSuccessTypes(Content $content): array
    {
        $previous = WorkflowInstance::query()
            ->where('content_id', $content->id)
            ->latest('id')->first();

        if ($previous === null) {
            return [];
        }

        return $previous->jobs()
            ->where('status', JobStatus::Success->value)
            ->whereNotIn('job_type', ['PUBLISH', 'CLEANUP', 'INDEX']) // 게시·색인은 항상 재실행
            ->pluck('job_type')
            ->map(fn ($type) => $type->value) // enum cast 해제
            ->all();
    }
}
