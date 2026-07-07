<?php

namespace App\Services\Workflow\Admin;

/**
 * 요청 스코프 감사 컨텍스트 — audit.context 미들웨어가 actor/ip/UA를 수집만 한다(저장 안 함).
 * 저장 경로는 audit.write 미들웨어가 통과시킨 조작 요청에서만 활성화된다 (Revision Checklist §5).
 */
class AuditContext
{
    private ?int $userId = null;

    private ?string $ip = null;

    private ?string $userAgent = null;

    private bool $writable = false;

    public function capture(?int $userId, ?string $ip, ?string $userAgent): void
    {
        $this->userId = $userId;
        $this->ip = $ip;
        $this->userAgent = $userAgent;
    }

    /** audit.write 미들웨어(POST/PATCH/DELETE 전용)만 호출한다 */
    public function enableWrite(): void
    {
        $this->writable = true;
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }
}
