<?php

namespace App\Exceptions;

use DomainException;

/**
 * 비활성 transcode profile 실행 거부 — Transcode Profile Spec §15.
 * 운영 설정 오류이므로 재시도하지 않는다.
 */
class ProfileInactiveException extends DomainException
{
    public function __construct(public readonly string $profileCode)
    {
        parent::__construct("transcode profile {$profileCode} is inactive — execution refused");
    }
}
