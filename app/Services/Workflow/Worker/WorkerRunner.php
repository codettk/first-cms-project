<?php

namespace App\Services\Workflow\Worker;

use App\Enums\JobStatus;
use App\Enums\WorkerStatus;
use App\Exceptions\JobCanceledException;
use App\Exceptions\LeaseLostException;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Queue\JobClaimService;
use App\Services\Workflow\Queue\JobLockService;
use App\Services\Workflow\Queue\JobProgressService;
use App\Services\Workflow\Queue\JobRetryPolicy;
use App\Services\Workflow\StateMachine\JobStateMachine;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Worker 공통 실행 루프 — Worker Agent Spec §5 (claim → execute → finalize).
 *
 * worker_type별 차이는 supported_job_types 설정과 HandlerRegistry 디스패치로 표현되므로
 * 별도 하위 클래스 없이 단일 러너로 구현한다(Spec §2 구조와 동일 책임 배치).
 * 초기 구현: Worker 프로세스 1개 = Job 1개 — 동시성은 supervisor numprocs.
 */
class WorkerRunner
{
    private bool $shuttingDown = false;

    public function __construct(
        private readonly JobClaimService $claim,
        private readonly JobLockService $locks,
        private readonly WorkerAgentService $agents,
        private readonly JobRetryPolicy $retryPolicy,
        private readonly JobProgressService $progress,
        private readonly JobLogService $logs,
        private readonly JobStateMachine $jobStateMachine,
        private readonly HandlerRegistry $registry,
    ) {}

    /** SIGTERM/SIGINT — 신규 Claim 중단, 진행 중 job은 drain */
    public function requestShutdown(): void
    {
        $this->shuttingDown = true;
    }

    public function run(
        WorkflowWorkerAgent $agent,
        bool $once = false,
        int $maxJobs = 0,
        int $pollMinSeconds = 2,
        int $pollMaxSeconds = 5,
    ): int {
        $processed = 0;

        while (! $this->shuttingDown) {
            $agent->refresh();

            if (in_array($agent->status, [WorkerStatus::Disabled, WorkerStatus::Error], true)) {
                // Claim 중단, 프로세스는 유지 — 운영자 해제 대기
                if ($once) {
                    break;
                }
                $this->sleepWithJitter($pollMinSeconds, $pollMaxSeconds);

                continue;
            }

            $job = $this->claim->tryClaim($agent);

            if ($job === null) {
                $this->agents->heartbeat($agent);
                if ($once) {
                    break;
                }
                $this->sleepWithJitter($pollMinSeconds, $pollMaxSeconds);

                continue;
            }

            $this->process($job, $agent);
            $processed++;

            if ($once || ($maxJobs > 0 && $processed >= $maxJobs)) {
                break;
            }
        }

        $this->agents->markOffline($agent);

        return $processed;
    }

    public function process(WorkflowJob $job, WorkflowWorkerAgent $agent): void
    {
        $this->logs->info($job, 'attempt started', ['attempt_no' => $job->retry_count + 1]);

        try {
            $result = $this->executeJob($job, $agent);

            $result->success
                ? $this->finalizeSuccess($job, $agent, $result)
                : $this->finalizeFailure($job, $agent, $result);
        } catch (LeaseLostException) {
            // 결과 폐기 — Scheduler가 이미 회수했다 (fencing)
            $this->logs->warn($job, 'lease lost — result discarded');
        } catch (JobCanceledException) {
            $this->finalizeCanceled($job, $agent);
        } catch (Throwable $e) {
            $this->finalizeFailure($job, $agent, JobResult::systemError($e));
        } finally {
            $this->locks->release($job->id, $agent->id);
            $this->agents->clearCurrentJob($agent);
        }
    }

    private function executeJob(WorkflowJob $job, WorkflowWorkerAgent $agent): JobResult
    {
        $handler = $this->registry->resolve($job->job_type->value);

        if ($handler === null) {
            return JobResult::failure(
                \App\Enums\FailureType::SystemError,
                'NO_HANDLER',
                "no handler registered for job_type {$job->job_type->value}",
            );
        }

        $context = new JobExecutionContext(
            job: $job,
            worker: $agent,
            logger: $this->logs,
            progress: $this->progress,
            cancelToken: new CancellationToken(
                $job, $agent->id, $this->locks,
                fn (): bool => $this->shuttingDown,
            ),
        );

        return $handler->handle($job, $context);
    }

    private function finalizeSuccess(WorkflowJob $job, WorkflowWorkerAgent $agent, JobResult $result): void
    {
        $transition = DB::transaction(fn () => $this->jobStateMachine->transitionOwnedBy(
            $job, JobStatus::Success->value, $agent->id, "worker:{$agent->id}", null,
            [
                'result' => $result->resultData === [] ? null : json_encode($result->resultData),
                'finished_at' => now(),
            ],
        ));

        if (! $transition->ok) {
            $this->logs->warn($job, 'late success discarded by fencing', [
                'current_status' => $transition->conflictCurrentStatus,
            ]);

            return;
        }

        $this->progress->clear($job->id);
        $this->logs->info($job, 'attempt succeeded');
    }

    private function finalizeFailure(WorkflowJob $job, WorkflowWorkerAgent $agent, JobResult $result): void
    {
        $job->refresh();

        if ($job->status !== JobStatus::Running) {
            $this->logs->warn($job, 'late failure discarded — job no longer RUNNING');

            return;
        }

        $retry = $this->retryPolicy->shouldRetry($job, $result->isRetryable());

        $transition = DB::transaction(fn () => $this->jobStateMachine->transitionOwnedBy(
            $job,
            $retry ? JobStatus::Retry->value : JobStatus::Failed->value,
            $agent->id,
            "worker:{$agent->id}",
            $result->failReasonCode,
            $retry
                ? [
                    'fail_reason_code' => $result->failReasonCode,
                    'next_attempt_at' => $this->retryPolicy->nextAttemptAt($job, $result->failureType),
                ]
                : [
                    'fail_reason_code' => $result->failReasonCode,
                    'finished_at' => now(),
                ],
        ));

        if (! $transition->ok) {
            $this->logs->warn($job, 'late failure discarded by fencing', [
                'current_status' => $transition->conflictCurrentStatus,
            ]);

            return;
        }

        $this->progress->clear($job->id);
        $this->logs->error($job, $result->failMessage ?? 'attempt failed', [
            'fail_reason_code' => $result->failReasonCode,
            'failure_type' => $result->failureType?->value,
            'transitioned_to' => $retry ? 'RETRY' : 'FAILED',
        ]);
    }

    private function finalizeCanceled(WorkflowJob $job, WorkflowWorkerAgent $agent): void
    {
        $transition = DB::transaction(fn () => $this->jobStateMachine->transitionOwnedBy(
            $job, JobStatus::Canceled->value, $agent->id, "worker:{$agent->id}",
            'cancel_requested', ['finished_at' => now()],
        ));

        if ($transition->ok) {
            $this->progress->clear($job->id);
            $this->logs->info($job, 'canceled by request');
        }
    }

    private function sleepWithJitter(int $min, int $max): void
    {
        usleep(random_int($min * 1_000_000, $max * 1_000_000));
    }
}
