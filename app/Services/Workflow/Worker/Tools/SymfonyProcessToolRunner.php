<?php

namespace App\Services\Workflow\Worker\Tools;

use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Symfony Process 기반 실행기 — Worker Agent Spec §10.
 * timeout 초과 시 프로세스 kill + exit 124(관례)로 반환하여 호출부가 TOOL_TIMEOUT으로 분류한다.
 */
class SymfonyProcessToolRunner implements ToolRunner
{
    public function run(array $command, ?int $timeoutSec = null, ?Closure $onStdoutLine = null): ToolResult
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSec);

        $stdout = '';
        $stderr = '';
        $lineBuffer = '';

        try {
            $process->run(function (string $type, string $buffer) use (&$stdout, &$stderr, &$lineBuffer, $onStdoutLine) {
                if ($type === Process::OUT) {
                    $stdout .= $buffer;

                    if ($onStdoutLine !== null) {
                        $lineBuffer .= $buffer;
                        while (($pos = strpos($lineBuffer, "\n")) !== false) {
                            $onStdoutLine(rtrim(substr($lineBuffer, 0, $pos), "\r"));
                            $lineBuffer = substr($lineBuffer, $pos + 1);
                        }
                    }
                } else {
                    $stderr .= $buffer;
                }
            });
        } catch (ProcessTimedOutException) {
            return new ToolResult(124, $stdout, $stderr."\nprocess timed out after {$timeoutSec}s");
        }

        return new ToolResult($process->getExitCode() ?? 1, $stdout, $stderr);
    }
}
