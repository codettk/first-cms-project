<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * lease 연장 2회 연속 실패 — Worker는 즉시 실행을 중단하고 결과를 폐기한다 (Worker Agent Spec §7).
 */
class LeaseLostException extends RuntimeException
{
    public function __construct(public readonly int $jobId)
    {
        parent::__construct("lease lost for job {$jobId} — result must be discarded");
    }
}
