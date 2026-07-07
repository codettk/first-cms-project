<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 실행 중 취소 감지 — Worker는 외부 프로세스를 중단하고 RUNNING→CANCELED 전이를 수행한다
 * (State Machine Spec §11 · Worker Agent Spec §14).
 */
class JobCanceledException extends RuntimeException
{
    public function __construct(public readonly int $jobId)
    {
        parent::__construct("cancel requested for job {$jobId}");
    }
}
