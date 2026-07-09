<?php

namespace App\Exceptions;

/**
 * release-lock 대상 lock 부재 — 409 JOB_LOCK_NOT_FOUND (Controller Service Spec §13).
 */
class JobLockNotFoundException extends WorkflowException
{
    public function __construct(public readonly int $jobId)
    {
        parent::__construct("no lock found for job {$jobId}");
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'JOB_LOCK_NOT_FOUND';
    }
}
