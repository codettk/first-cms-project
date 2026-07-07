<?php

namespace Tests\Support;

use App\Services\Workflow\Worker\Tools\ToolResult;
use App\Services\Workflow\Worker\Tools\ToolRunner;
use Closure;

/**
 * 외부 도구 실행 페이크 — 호출 순서대로 큐의 응답 클로저를 실행한다.
 * 클로저는 (command, timeoutSec, onStdoutLine)를 받아 ToolResult를 반환한다.
 */
class FakeToolRunner implements ToolRunner
{
    /** @var list<Closure> */
    private array $queue = [];

    /** @var list<list<string>> 호출된 명령 기록 */
    public array $commands = [];

    public function push(Closure $responder): self
    {
        $this->queue[] = $responder;

        return $this;
    }

    /** exit 0 + 출력 파일 생성 응답 헬퍼 — 출력 경로는 명령의 마지막 인자 */
    public function pushWritesOutput(string $contents = 'fake-output', ?Closure $beforeReturn = null): self
    {
        return $this->push(function (array $command, ?int $timeout, ?Closure $onLine) use ($contents, $beforeReturn) {
            file_put_contents(end($command), $contents);
            $beforeReturn?->__invoke($command, $onLine);

            return new ToolResult(0, '', '');
        });
    }

    /** ffprobe JSON 응답 헬퍼 */
    public function pushProbeJson(array $json): self
    {
        return $this->push(fn () => new ToolResult(0, json_encode($json), ''));
    }

    public function pushFailure(int $exitCode = 1, string $stderr = 'tool failed'): self
    {
        return $this->push(fn () => new ToolResult($exitCode, '', $stderr));
    }

    public function run(array $command, ?int $timeoutSec = null, ?Closure $onStdoutLine = null): ToolResult
    {
        $this->commands[] = $command;

        $responder = array_shift($this->queue)
            ?? fn () => new ToolResult(1, '', 'FakeToolRunner: no queued response');

        return $responder($command, $timeoutSec, $onStdoutLine);
    }
}
