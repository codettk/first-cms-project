<?php

namespace App\Services\Workflow;

use App\Enums\InstanceStatus;
use App\Enums\JobStatus;
use App\Models\Content;
use App\Models\WorkflowInstance;
use App\Models\WorkflowJob;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\StateMachine\JobStateMachine;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 템플릿 기반 workflow_instance + jobs + dependencies 생성 — Roadmap Phase 2.
 *
 * 호출부(Scheduler의 REGISTERED→PROCESSING 전이·CommandService의 재처리)가 소유한
 * 트랜잭션 안에서 실행된다 — 인스턴스 생성과 콘텐츠 전이는 원자적이어야 한다 (Spec §3).
 * 콘텐츠당 RUNNING 인스턴스 1건은 partial UNIQUE(uq_instances_running)가 보장한다.
 */
class WorkflowInstanceFactory
{
    public function __construct(private readonly JobStateMachine $jobStateMachine) {}

    /**
     * @param int $priority 인스턴스 전체 job에 일괄 부여 — Queue Spec §12
     *                      (일반 100 · 관리자 재처리 200 · 긴급 300 · 재색인 50)
     * @param list<string> $reuseSuccessTypes failed_from 재처리 시 직전 성공 단계 —
     *                                        SUCCESS로 생성해 산출물(rendition)을 재사용한다
     */
    public function createForContent(
        Content $content,
        WorkflowTemplate $template,
        int $priority = 100,
        string $actor = 'scheduler',
        array $reuseSuccessTypes = [],
    ): WorkflowInstance {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(self::class.'는 호출부가 소유한 트랜잭션 안에서만 실행해야 한다');
        }

        $steps = $template->steps()->orderBy('step_order')->get();

        if ($steps->isEmpty()) {
            throw new DomainException("template {$template->code} has no steps");
        }

        $this->assertValidDag($steps);

        $instance = WorkflowInstance::create([
            'content_id' => $content->id,
            'template_id' => $template->id,
            'status' => InstanceStatus::Running,
        ]);

        /** @var array<string, WorkflowJob> $jobsByType */
        $jobsByType = [];

        foreach ($steps as $step) {
            $reused = in_array($step->job_type->value, $reuseSuccessTypes, true);

            $job = WorkflowJob::create([
                'instance_id' => $instance->id,
                'content_id' => $content->id,
                'job_type' => $step->job_type,
                'status' => $reused ? JobStatus::Success : JobStatus::Waiting,
                'is_required' => $step->is_required,
                'priority' => $priority,
                'max_retry' => $step->max_retry,
                'timeout_sec' => $step->timeout_sec,
                'payload' => $step->config,
                'finished_at' => $reused ? now() : null,
            ]);

            $this->jobStateMachine->recordCreation(
                $job, $actor, $reused ? 'reused_previous_success' : null
            );
            $jobsByType[$step->job_type->value] = $job;
        }

        $dependencyRows = [];
        foreach ($steps as $step) {
            foreach ($step->depends_on ?? [] as $dependsOnType) {
                $dependencyRows[] = [
                    'job_id' => $jobsByType[$step->job_type->value]->id,
                    'depends_on_job_id' => $jobsByType[$dependsOnType]->id,
                ];
            }
        }

        if ($dependencyRows !== []) {
            DB::table('workflow_job_dependencies')->insert($dependencyRows);
        }

        return $instance;
    }

    /**
     * depends_on 참조 무결성 + 사이클 검증 (Kahn 위상 정렬).
     *
     * @param Collection<int, \App\Models\WorkflowTemplateStep> $steps
     */
    private function assertValidDag(Collection $steps): void
    {
        $types = $steps->map(fn ($step) => $step->job_type->value)->all();

        $inDegree = array_fill_keys($types, 0);
        $dependents = array_fill_keys($types, []);

        foreach ($steps as $step) {
            $type = $step->job_type->value;
            foreach ($step->depends_on ?? [] as $dependsOnType) {
                if (! array_key_exists($dependsOnType, $inDegree)) {
                    throw new DomainException(
                        "step {$type} depends on unknown step {$dependsOnType}"
                    );
                }
                $inDegree[$type]++;
                $dependents[$dependsOnType][] = $type;
            }
        }

        $queue = array_keys(array_filter($inDegree, fn (int $degree) => $degree === 0));
        $visited = 0;

        while ($queue !== []) {
            $current = array_shift($queue);
            $visited++;
            foreach ($dependents[$current] as $dependent) {
                if (--$inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if ($visited !== count($types)) {
            throw new DomainException('template steps contain a dependency cycle');
        }
    }
}
