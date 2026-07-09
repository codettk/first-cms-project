<?php

namespace App\Services\Workflow\Worker;

use App\Models\Content;
use App\Models\MediaFile;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Queue\JobProgressService;

/**
 * Handler 실행 컨텍스트 — Worker Agent Spec §8.
 */
final class JobExecutionContext
{
    public function __construct(
        public readonly WorkflowJob $job,
        public readonly WorkflowWorkerAgent $worker,
        public readonly JobLogService $logger,
        public readonly JobProgressService $progress,
        public readonly CancellationToken $cancelToken,
    ) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->job->payload ?? [];
    }

    public function content(): Content
    {
        return $this->job->content;
    }

    public function mediaFile(): ?MediaFile
    {
        return $this->content()->mediaFiles()->latest('id')->first();
    }

    public function attemptNo(): int
    {
        return $this->job->retry_count + 1;
    }
}
