<?php

namespace App\Services\Workflow\Scheduler;

use App\Enums\ContentStatus;
use App\Enums\IndexStatus;
use App\Enums\InstanceStatus;
use App\Enums\JobStatus;
use App\Enums\WorkerStatus;
use App\Models\Content;
use App\Models\SearchIndexState;
use App\Models\WorkflowInstance;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Admin\AlertService;
use App\Services\Workflow\Queue\JobRetryPolicy;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\StateMachine\JobStateMachine;
use App\Services\Workflow\StateMachine\WorkerStateMachine;
use App\Services\Workflow\StateMachine\WorkflowInstanceStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Scheduler 7단계 틱 — Queue Worker Spec §8 (+§11 취소 강제).
 *
 * 단일 실행은 pg advisory lock으로 보장한다. 모든 상태 전이는 StateMachine 경유 —
 * 스펙의 벌크 SQL은 후보 선별 조건으로만 사용하고, 전이는 job 단위 트랜잭션으로
 * 수행해 history 기록 의무(State Machine Spec §15)를 지킨다.
 */
class WorkflowSchedulerService
{
    /** pg advisory lock 키 — workflow scheduler 단일 실행 보장 */
    public const int ADVISORY_LOCK_KEY = 74100001;

    private const int WORKER_OFFLINE_AFTER_SECONDS = 90;

    private const int CANCEL_FORCE_AFTER_SECONDS = 30;

    public function __construct(
        private readonly JobStateMachine $jobs,
        private readonly ContentStateMachine $contents,
        private readonly WorkflowInstanceStateMachine $instances,
        private readonly WorkerStateMachine $workers,
        private readonly JobRetryPolicy $retryPolicy,
        private readonly AlertService $alerts,
    ) {}

    public function tryAcquireAdvisoryLock(): bool
    {
        return (bool) DB::selectOne('SELECT pg_try_advisory_lock(?) AS ok', [self::ADVISORY_LOCK_KEY])->ok;
    }

    public function releaseAdvisoryLock(): void
    {
        DB::selectOne('SELECT pg_advisory_unlock(?)', [self::ADVISORY_LOCK_KEY]);
    }

    /** @return array<string, int> 단계별 처리 건수 */
    public function tick(): array
    {
        return [
            'ready' => $this->promoteReadyJobs(),          // ① WAITING → READY
            'skipped' => $this->propagateSkips(),          // ② SKIPPED 전파 + 인스턴스/콘텐츠 FAILED
            'publish' => $this->gatePublishJobs(),         // ③ PUBLISH 판정
            'reindex' => $this->createStaleReindexJobs(),  // ③-b STALE 재색인 (SM Spec §14)
            'retry' => $this->resumeRetries(),             // ④ RETRY 재개 (retry_count 증가 — ADR-0003)
            'timeout' => $this->handleTimeouts(),          // ⑤ 타임아웃 감시
            'locks' => $this->reclaimExpiredLocks(),       // ⑥ 만료 Lock 회수
            'workers' => $this->checkWorkerHealth(),       // ⑦ Worker 헬스
            'canceled' => $this->enforceCancellations(),   // 취소 무반응 강제 전이 (SM Spec §11)
        ];
    }

    /** ① 의존 충족 WAITING → READY (SQL-1 조건 · PUBLISH는 ③에서 별도 판정) */
    private function promoteReadyJobs(): int
    {
        $candidates = WorkflowJob::query()
            ->where('workflow_jobs.status', JobStatus::Waiting->value)
            ->where('workflow_jobs.job_type', '<>', 'PUBLISH')
            // SUCCESS 포함 — CLEANUP은 PUBLISH가 인스턴스를 SUCCESS로 종결한 뒤 실행된다.
            // instance_id NULL은 단독 재색인 job — 인스턴스 수명과 무관 (ADR-0007)
            ->where(function ($q) {
                $q->whereNull('workflow_jobs.instance_id')
                    ->orWhereHas('instance', fn ($i) => $i->whereIn(
                        'status', [InstanceStatus::Running->value, InstanceStatus::Success->value]
                    ));
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('workflow_job_dependencies as d')
                    ->join('workflow_jobs as p', 'p.id', '=', 'd.depends_on_job_id')
                    ->whereColumn('d.job_id', 'workflow_jobs.id')
                    ->whereNotIn('p.status', [JobStatus::Success->value, JobStatus::Skipped->value]);
            })
            ->get();

        $count = 0;
        foreach ($candidates as $job) {
            $result = DB::transaction(fn () => $this->jobs->transition(
                $job, JobStatus::Ready->value, 'scheduler', 'deps_satisfied'
            ));
            $count += $result->ok ? 1 : 0;
        }

        return $count;
    }

    /** ② 필수 job 최종 FAILED / CANCELED 전파 — 후속 SKIPPED + 인스턴스/콘텐츠 종결 */
    private function propagateSkips(): int
    {
        $count = 0;

        // (a) 필수 작업 최종 실패 — 후속 SKIPPED + 인스턴스 FAILED + 콘텐츠 FAILED
        $failedRequired = WorkflowJob::query()
            ->where('status', JobStatus::Failed->value)
            ->where('is_required', true)
            ->whereRelation('instance', 'status', InstanceStatus::Running->value)
            ->get();

        foreach ($failedRequired as $failed) {
            $count += $this->skipDownstream($failed, 'SKIP_UPSTREAM_FAILED');

            $instance = $failed->instance;
            DB::transaction(function () use ($instance) {
                $this->instances->transition(
                    $instance, InstanceStatus::Failed->value, 'scheduler',
                    null, ['finished_at' => now()],
                );

                $content = $instance->content;
                if ($content->status === ContentStatus::Processing) {
                    $this->contents->transition($content, ContentStatus::Failed->value, 'scheduler');
                }
            });

            // 관리자 즉시 알림 — HIGH + 이메일 채널 (Wireframe §13 · ADR-0006)
            $this->alerts->raise(
                'HIGH', 'REQUIRED_JOB_FAILED',
                ($instance->content->title ?? "content #{$failed->content_id}")
                    ." {$failed->job_type->value} 실패 — 콘텐츠 처리 중단",
                contentId: $failed->content_id,
                jobId: $failed->id,
                actionLink: "/admin/workflows/jobs/{$failed->id}",
            );
        }

        // (b) 취소 전파 — CANCELED job의 후속은 필수 여부 무관 SKIPPED, 필수면 인스턴스 CANCELED
        $canceled = WorkflowJob::query()
            ->where('status', JobStatus::Canceled->value)
            ->whereRelation('instance', 'status', InstanceStatus::Running->value)
            ->get();

        foreach ($canceled as $job) {
            $count += $this->skipDownstream($job, 'SKIP_UPSTREAM_CANCELED');

            if ($job->is_required) {
                DB::transaction(fn () => $this->instances->transition(
                    $job->instance, InstanceStatus::Canceled->value, 'scheduler',
                    'upstream_canceled', ['finished_at' => now()],
                ));
            }
        }

        return $count;
    }

    /** 후속 job(WAITING/READY) 전이 대상 BFS — SKIPPED 처리 건수 반환 */
    private function skipDownstream(WorkflowJob $origin, string $reasonCode): int
    {
        $count = 0;
        $frontier = [$origin->id];
        $seen = [];

        while ($frontier !== []) {
            $dependentIds = DB::table('workflow_job_dependencies')
                ->whereIn('depends_on_job_id', $frontier)
                ->pluck('job_id')
                ->diff($seen)
                ->all();

            $frontier = [];

            foreach (WorkflowJob::query()->whereIn('id', $dependentIds)->get() as $dependent) {
                $seen[] = $dependent->id;
                $frontier[] = $dependent->id;

                if (in_array($dependent->status, [JobStatus::Waiting, JobStatus::Ready], true)) {
                    $result = DB::transaction(fn () => $this->jobs->transition(
                        $dependent, JobStatus::Skipped->value, 'scheduler', $reasonCode,
                        ['finished_at' => now()],
                    ));
                    $count += $result->ok ? 1 : 0;
                }
            }
        }

        return $count;
    }

    /** ③ PUBLISH 판정 — 필수 job(PUBLISH·CLEANUP 제외) 전체 SUCCESS + 의존 충족 시 READY */
    private function gatePublishJobs(): int
    {
        $candidates = WorkflowJob::query()
            ->where('workflow_jobs.status', JobStatus::Waiting->value)
            ->where('workflow_jobs.job_type', 'PUBLISH')
            ->whereRelation('instance', 'status', InstanceStatus::Running->value)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('workflow_job_dependencies as d')
                    ->join('workflow_jobs as p', 'p.id', '=', 'd.depends_on_job_id')
                    ->whereColumn('d.job_id', 'workflow_jobs.id')
                    ->whereNotIn('p.status', [JobStatus::Success->value, JobStatus::Skipped->value]);
            })
            ->whereNotExists(function ($q) {
                // 선택 작업 실패는 무시 — 필수 작업만 차단 사유
                $q->select(DB::raw(1))
                    ->from('workflow_jobs as r')
                    ->whereColumn('r.instance_id', 'workflow_jobs.instance_id')
                    ->where('r.is_required', true)
                    ->whereNotIn('r.job_type', ['PUBLISH', 'CLEANUP'])
                    ->where('r.status', '<>', JobStatus::Success->value);
            })
            ->get();

        $count = 0;
        foreach ($candidates as $job) {
            $result = DB::transaction(fn () => $this->jobs->transition(
                $job, JobStatus::Ready->value, 'scheduler', 'publish_gate'
            ));
            $count += $result->ok ? 1 : 0;
        }

        return $count;
    }

    /**
     * ③-b STALE 재색인 — 인스턴스 무소속 단독 INDEX job 생성 (SM Spec §14 · ADR-0007).
     * 메타 수정·선택 작업(OCR 등) SUCCESS가 INDEXED→STALE로 마킹한 상태를 소진한다.
     */
    private function createStaleReindexJobs(): int
    {
        $staleStates = SearchIndexState::query()
            ->where('status', IndexStatus::Stale->value)
            ->get();

        $count = 0;

        foreach ($staleStates as $state) {
            // 비종결 INDEX job이 이미 있으면 그 실행이 STALE을 소진한다 — 중복 생성 금지
            $active = WorkflowJob::query()
                ->where('content_id', $state->content_id)
                ->where('job_type', 'INDEX')
                ->whereIn('status', [
                    JobStatus::Waiting->value, JobStatus::Ready->value,
                    JobStatus::Running->value, JobStatus::Retry->value,
                ])
                ->exists();

            if ($active) {
                continue;
            }

            // READY 콘텐츠만 자동 재색인 — FAILED/ARCHIVED/DELETED는 수동 대상 (ADR-0007)
            $contentReady = Content::query()
                ->whereKey($state->content_id)
                ->where('status', ContentStatus::Ready->value)
                ->exists();

            if (! $contentReady) {
                continue;
            }

            DB::transaction(function () use ($state) {
                $job = WorkflowJob::create([
                    'instance_id' => null,  // 단독 job — 기존 인스턴스와 무관 (SM Spec §14)
                    'content_id' => $state->content_id,
                    'job_type' => 'INDEX',
                    'status' => JobStatus::Waiting,
                    'is_required' => false, // 재색인 실패가 콘텐츠를 차단하면 안 된다 (SM Spec §7)
                    'priority' => 50,       // 재색인 우선순위 (Queue Worker Spec §12)
                    'max_retry' => 5,
                    'timeout_sec' => 300,
                ]);

                $this->jobs->recordCreation($job, 'scheduler', 'stale_reindex');
            });

            $count++;
        }

        return $count;
    }

    /** ④ RETRY 재개 — 소진 검사 후 READY(+retry_count 증가) 또는 FAILED (ADR-0003) */
    private function resumeRetries(): int
    {
        $due = WorkflowJob::query()
            ->where('status', JobStatus::Retry->value)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->get();

        $count = 0;
        foreach ($due as $job) {
            $result = DB::transaction(function () use ($job) {
                if ($this->retryPolicy->isExhausted($job)) {
                    return $this->jobs->transition(
                        $job, JobStatus::Failed->value, 'scheduler', 'retry_exhausted',
                        ['finished_at' => now()],
                    );
                }

                return $this->jobs->transition(
                    $job, JobStatus::Ready->value, 'scheduler', 'retry_resume',
                    ['retry_count' => $job->retry_count + 1],
                );
            });
            $count += $result->ok ? 1 : 0;
        }

        return $count;
    }

    /** ⑤ 타임아웃 — RUNNING→TIMEOUT + lock 삭제 + RETRY/FAILED 재분류 (단일 트랜잭션, SM Spec §10) */
    private function handleTimeouts(): int
    {
        $expired = WorkflowJob::query()
            ->where('status', JobStatus::Running->value)
            ->whereRaw('started_at + make_interval(secs => timeout_sec) < now()')
            ->get();

        $count = 0;
        foreach ($expired as $job) {
            DB::transaction(function () use ($job) {
                $timeout = $this->jobs->transition(
                    $job, JobStatus::Timeout->value, 'system', 'timeout'
                );
                if (! $timeout->ok) {
                    return;
                }

                DB::table('workflow_job_locks')->where('job_id', $job->id)->delete();

                $job->refresh();
                if ($this->retryPolicy->isExhausted($job)) {
                    $this->jobs->transition(
                        $job, JobStatus::Failed->value, 'system', 'timeout_exhausted',
                        ['finished_at' => now(), 'fail_reason_code' => 'TOOL_TIMEOUT'],
                    );
                } else {
                    $this->jobs->transition(
                        $job, JobStatus::Retry->value, 'system', 'timeout_retry',
                        [
                            'fail_reason_code' => 'TOOL_TIMEOUT',
                            'next_attempt_at' => $this->retryPolicy->nextAttemptAt($job),
                        ],
                    );
                }
            });
            $count++;
        }

        return $count;
    }

    /** ⑥ 만료 Lock 회수 — lock 삭제 + RUNNING job RETRY/FAILED (actor=system, note=lease_expired) */
    private function reclaimExpiredLocks(): int
    {
        $expired = DB::table('workflow_job_locks')
            ->where('locked_until', '<', now())
            ->get();

        $count = 0;
        foreach ($expired as $lock) {
            $count += $this->reclaimLock($lock->job_id, 'lease_expired', 'LEASE_EXPIRED');
        }

        return $count;
    }

    private function reclaimLock(int $jobId, string $note, string $failReasonCode): int
    {
        return DB::transaction(function () use ($jobId, $note, $failReasonCode) {
            DB::table('workflow_job_locks')->where('job_id', $jobId)->delete();

            $job = WorkflowJob::query()->find($jobId);
            if ($job === null || $job->status !== JobStatus::Running) {
                return 0;
            }

            if ($this->retryPolicy->isExhausted($job)) {
                $result = $this->jobs->transition(
                    $job, JobStatus::Failed->value, 'system', $note,
                    ['finished_at' => now(), 'fail_reason_code' => $failReasonCode],
                );
            } else {
                $result = $this->jobs->transition(
                    $job, JobStatus::Retry->value, 'system', $note,
                    [
                        'fail_reason_code' => $failReasonCode,
                        'next_attempt_at' => $this->retryPolicy->nextAttemptAt($job),
                    ],
                );
            }

            return $result->ok ? 1 : 0;
        });
    }

    /** ⑦ Worker 헬스 — heartbeat 90초 미수신 → OFFLINE + 보유 lock 전부 회수 */
    private function checkWorkerHealth(): int
    {
        $stale = WorkflowWorkerAgent::query()
            ->whereIn('status', [WorkerStatus::Online->value, WorkerStatus::Busy->value])
            ->where(function ($q) {
                $q->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<', now()->subSeconds(self::WORKER_OFFLINE_AFTER_SECONDS));
            })
            ->get();

        $count = 0;
        foreach ($stale as $agent) {
            DB::transaction(fn () => $this->workers->transition(
                $agent, WorkerStatus::Offline->value, 'system', 'heartbeat_lost',
                ['current_job_id' => null],
            ));

            $locks = DB::table('workflow_job_locks')
                ->where('locked_by_worker_id', $agent->id)
                ->get();

            foreach ($locks as $lock) {
                $this->reclaimLock($lock->job_id, 'worker_offline', 'WORKER_CRASH');
            }

            // 관리자 알림 — HIGH (Wireframe §13 · ADR-0006)
            $this->alerts->raise(
                'HIGH', 'WORKER_OFFLINE',
                "{$agent->worker_name} 연결 끊김 (".self::WORKER_OFFLINE_AFTER_SECONDS.'초)',
                workerId: $agent->id,
                actionLink: '/admin/workflows/workers',
            );

            $count++;
        }

        return $count;
    }

    /** 취소 요청 후 30초 무반응 RUNNING → Scheduler 강제 CANCELED + lock 회수 (SM Spec §11) */
    private function enforceCancellations(): int
    {
        $unresponsive = WorkflowJob::query()
            ->where('status', JobStatus::Running->value)
            ->where('cancel_requested_at', '<', now()->subSeconds(self::CANCEL_FORCE_AFTER_SECONDS))
            ->get();

        $count = 0;
        foreach ($unresponsive as $job) {
            DB::transaction(function () use ($job) {
                $result = $this->jobs->transition(
                    $job, JobStatus::Canceled->value, 'system', 'cancel_forced',
                    ['finished_at' => now()],
                );
                if ($result->ok) {
                    DB::table('workflow_job_locks')->where('job_id', $job->id)->delete();
                    DB::table('workflow_job_progresses')->where('job_id', $job->id)->delete();
                }
            });
            $count++;
        }

        return $count;
    }
}
