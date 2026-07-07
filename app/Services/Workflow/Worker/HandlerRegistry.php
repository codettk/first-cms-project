<?php

namespace App\Services\Workflow\Worker;

/**
 * job_type → Handler 해석 — Worker Agent Spec §2.
 * MVP는 TM·VERIFY·MA·TC·CA·INDEX·PUBLISH·CLEANUP 8종만 등록한다
 * (OCR·STT·AI_ANALYSIS·HLS·WAVEFORM·IMAGE_TC·AUDIO_TC·DOC_PREVIEW·TEXT_EXTRACT는 M6 확장).
 */
class HandlerRegistry
{
    /** @var list<WorkflowJobHandler> */
    private array $handlers = [];

    public function register(WorkflowJobHandler $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function resolve(string $jobType): ?WorkflowJobHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($jobType)) {
                return $handler;
            }
        }

        return null;
    }
}
