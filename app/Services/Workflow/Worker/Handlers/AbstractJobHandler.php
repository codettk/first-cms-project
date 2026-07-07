<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Models\WorkflowJob;
use App\Services\Workflow\Worker\WorkflowJobHandler;

abstract class AbstractJobHandler implements WorkflowJobHandler
{
    protected const string JOB_TYPE = '';

    public function supports(string $jobType): bool
    {
        return $jobType === static::JOB_TYPE;
    }

    /** 외부 프로세스 중단은 CancellationToken(tick) 경유가 기본 — 필요 시 오버라이드 */
    public function cancel(WorkflowJob $job): void {}
}
