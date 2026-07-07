<?php

namespace App\Services\Workflow\Worker;

use App\Models\WorkflowJob;

/**
 * Job Handler 계약 — Worker Agent Spec §8.
 */
interface WorkflowJobHandler
{
    public function supports(string $jobType): bool;

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult;

    /** 외부 프로세스 kill + 부분 산출물 정리 */
    public function cancel(WorkflowJob $job): void;
}
