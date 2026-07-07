<?php

namespace App\Enums;

/**
 * 실패 분류 — Worker Agent Spec §13. retryable 기본값과 백오프 base 오버라이드를 정의한다.
 */
enum FailureType: string
{
    case Transient = 'TRANSIENT';
    case Permanent = 'PERMANENT';
    case UserFileError = 'USER_FILE_ERROR';
    case SystemError = 'SYSTEM_ERROR';
    case WorkerError = 'WORKER_ERROR';
    case StorageError = 'STORAGE_ERROR';
    case ExternalToolError = 'EXTERNAL_TOOL_ERROR';
    case SearchEngineError = 'SEARCH_ENGINE_ERROR';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Permanent, self::UserFileError => false,
            default => true,
        };
    }

    /** FailureType별 백오프 base 오버라이드 (초) — null이면 job_type 기본값 사용 */
    public function backoffBaseSeconds(): ?int
    {
        return $this === self::SearchEngineError ? 30 : null;
    }
}
