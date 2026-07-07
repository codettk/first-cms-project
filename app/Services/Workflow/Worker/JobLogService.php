<?php

namespace App\Services\Workflow\Worker;

use App\Models\WorkflowJob;
use Illuminate\Support\Facades\DB;

/**
 * workflow_job_logs 기록 — Worker Agent Spec §16.
 * histories(상태 전이)와 logs(command·stdout·stderr·detail)는 분리 원칙.
 */
class JobLogService
{
    private const int STREAM_LIMIT_BYTES = 1_048_576; // 1MB

    /**
     * @param array<string, mixed> $detail
     */
    public function log(
        WorkflowJob $job,
        string $level,
        string $message,
        array $detail = [],
        ?string $command = null,
        ?string $stdout = null,
        ?string $stderr = null,
    ): void {
        DB::table('workflow_job_logs')->insert([
            'job_id' => $job->id,
            'attempt_no' => $job->retry_count + 1,
            'level' => $level,
            'command' => $command,
            'stdout' => $stdout !== null ? $this->truncate($stdout) : null,
            'stderr' => $stderr !== null ? $this->truncate($stderr) : null,
            'message' => $message,
            'detail' => $detail === [] ? null : json_encode($detail),
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $detail */
    public function info(WorkflowJob $job, string $message, array $detail = [], ?string $command = null): void
    {
        $this->log($job, 'INFO', $message, $detail, $command);
    }

    /** @param array<string, mixed> $detail */
    public function warn(WorkflowJob $job, string $message, array $detail = []): void
    {
        $this->log($job, 'WARN', $message, $detail);
    }

    /** @param array<string, mixed> $detail */
    public function error(WorkflowJob $job, string $message, array $detail = [], ?string $stderr = null): void
    {
        $this->log($job, 'ERROR', $message, $detail, stderr: $stderr);
    }

    /** 1MB 상한 — head 256KB + tail 768KB 절단 표기 (Spec §16) */
    private function truncate(string $stream): string
    {
        if (strlen($stream) <= self::STREAM_LIMIT_BYTES) {
            return $stream;
        }

        return substr($stream, 0, 262_144)
            ."\n…[truncated]…\n"
            .substr($stream, -786_432);
    }
}
