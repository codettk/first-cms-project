<?php

namespace App\Services\Workflow\Worker\Tools;

use Closure;

/**
 * 외부 도구 호출 계약 — Worker Agent Spec §10.
 * 쉘 문자열 금지 — 인자 배열만 (command injection 원천 차단).
 */
interface ToolRunner
{
    /**
     * @param list<string> $command 실행 파일 + 인자 배열
     * @param Closure(string): void|null $onStdoutLine 진행률 파싱용 stdout 라인 콜백
     */
    public function run(array $command, ?int $timeoutSec = null, ?Closure $onStdoutLine = null): ToolResult;
}
