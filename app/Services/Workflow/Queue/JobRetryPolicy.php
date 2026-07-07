<?php

namespace App\Services\Workflow\Queue;

use App\Enums\FailureType;
use App\Enums\JobType;
use App\Models\WorkflowJob;
use Carbon\CarbonImmutable;

/**
 * 재시도 판정 + 지수 백오프 — Queue Worker Spec §11 · Worker Agent Spec §13.
 * next_attempt_at = now() + base × 2^retry_count (base 60초·INDEX 30초, 상한 30분).
 */
class JobRetryPolicy
{
    private const int DEFAULT_BASE_SECONDS = 60;

    private const int INDEX_BASE_SECONDS = 30;

    private const int MAX_BACKOFF_SECONDS = 1800;

    public function isExhausted(WorkflowJob $job): bool
    {
        return $job->retry_count >= $job->max_retry;
    }

    public function shouldRetry(WorkflowJob $job, bool $retryable): bool
    {
        return $retryable && ! $this->isExhausted($job);
    }

    public function backoffSeconds(WorkflowJob $job, ?FailureType $failureType = null): int
    {
        $base = $failureType?->backoffBaseSeconds()
            ?? ($job->job_type === JobType::Index ? self::INDEX_BASE_SECONDS : self::DEFAULT_BASE_SECONDS);

        return (int) min($base * (2 ** $job->retry_count), self::MAX_BACKOFF_SECONDS);
    }

    public function nextAttemptAt(WorkflowJob $job, ?FailureType $failureType = null): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds($this->backoffSeconds($job, $failureType));
    }
}
