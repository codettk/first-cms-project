<?php

namespace App\Services\Workflow\Worker;

use App\Enums\FailureType;
use Throwable;

/**
 * Handler 실행 결과 — Worker Agent Spec §8.
 */
final readonly class JobResult
{
    /**
     * @param array<string, mixed> $resultData job.result에 저장
     * @param list<array<string, mixed>> $renditions RenditionService가 upsert할 스펙 목록
     * @param bool|null $retryable FailureType 기본값 오버라이드 (예: 비활성 프로파일 = SYSTEM_ERROR지만 재시도 안 함 — Transcode Profile Spec §15)
     */
    private function __construct(
        public bool $success,
        public array $resultData = [],
        public array $renditions = [],
        public ?string $failReasonCode = null,
        public ?string $failMessage = null,
        public ?FailureType $failureType = null,
        public ?bool $retryable = null,
    ) {}

    /**
     * @param array<string, mixed> $resultData
     * @param list<array<string, mixed>> $renditions
     */
    public static function success(array $resultData = [], array $renditions = []): self
    {
        return new self(true, $resultData, $renditions);
    }

    public static function failure(
        FailureType $type,
        string $failReasonCode,
        string $failMessage,
        ?bool $retryable = null,
    ): self {
        return new self(
            false,
            failReasonCode: $failReasonCode,
            failMessage: $failMessage,
            failureType: $type,
            retryable: $retryable,
        );
    }

    public static function systemError(Throwable $e): self
    {
        return self::failure(FailureType::SystemError, 'UNEXPECTED_EXCEPTION', $e->getMessage());
    }

    public function isRetryable(): bool
    {
        return $this->retryable ?? $this->failureType?->isRetryable() ?? false;
    }
}
