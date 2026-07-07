<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 워크플로우 도메인 예외 베이스 — HTTP status + error code 매핑 (Controller Service Spec §13).
 */
abstract class WorkflowException extends RuntimeException
{
    abstract public function httpStatus(): int;

    abstract public function errorCode(): string;

    /** @return array<string, mixed> ErrorResponse.error에 병합할 추가 필드 */
    public function errorContext(): array
    {
        return [];
    }
}
