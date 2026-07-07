<?php

namespace App\Services\Workflow\Worker\Tools;

/**
 * 외부 도구 실행 결과 — Worker Agent Spec §10.
 */
final readonly class ToolResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** exit ≠ 0 시 stderr 마지막 N줄을 fail_message로 사용 (Spec §10) */
    public function stderrTail(int $lines = 20): string
    {
        $all = preg_split('/\r\n|\r|\n/', trim($this->stderr)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
