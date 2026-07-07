<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * MASTER zone 변조 시도 차단 — Worker Agent Spec §15.
 * MASTER는 TM의 최초 쓰기 외 어떤 수정·삭제도 허용하지 않는다.
 */
class MasterZoneProtectionException extends RuntimeException
{
    public function __construct(string $operation, string $path)
    {
        parent::__construct("MASTER zone mutation blocked: {$operation} on {$path}");
    }
}
