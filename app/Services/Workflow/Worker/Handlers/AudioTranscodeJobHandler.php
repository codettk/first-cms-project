<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Enums\StorageZone;
use App\Exceptions\ProfileInactiveException;
use App\Models\MediaRendition;
use App\Models\WorkflowJob;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Storage\ProfileCompiler;
use App\Services\Workflow\Storage\RenditionService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\Tools\MediaProber;
use App\Services\Workflow\Worker\Tools\ToolRunner;
use DomainException;

/**
 * AUDIO_TC — 웹 미리듣기용 프록시 오디오 생성 (Job Type Def §3.6 · Transcode Profile Spec §6).
 * ffmpeg AAC 128k(기본). 성공 조건: 출력 존재 AND 재생 길이 원본 대비 ±0.5초 이내.
 */
class AudioTranscodeJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'AUDIO_TC';

    /** 원본 대비 허용 길이 편차 — Job Type Def §3.6 성공 조건 */
    private const int DURATION_TOLERANCE_MS = 500;

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly RenditionService $renditions,
        private readonly ProfileCompiler $compiler,
        private readonly ToolRunner $runner,
        private readonly MediaProber $prober,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $profileCode = $ctx->payload()['profile'] ?? null;

        if (! is_string($profileCode) || $profileCode === '') {
            return JobResult::failure(FailureType::SystemError, 'PROFILE_MISSING', 'payload.profile is required', retryable: false);
        }

        try {
            $profile = $this->compiler->loadByCode($profileCode);
        } catch (ProfileInactiveException $e) {
            return JobResult::failure(FailureType::SystemError, 'PROFILE_INACTIVE', $e->getMessage(), retryable: false);
        } catch (DomainException $e) {
            return JobResult::failure(FailureType::SystemError, 'PROFILE_MISSING', $e->getMessage(), retryable: false);
        }

        $master = MediaRendition::query()
            ->where('content_id', $job->content_id)
            ->where('rendition_type', 'MASTER')
            ->first();

        if ($master === null) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'MASTER rendition row missing');
        }

        $inputAbs = $this->storage->absolutePath($master->storage_zone, $master->path);

        if (! is_file($inputAbs)) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'master file missing on storage');
        }

        $sourceDurationMs = $this->sourceDurationMs($ctx, $inputAbs);

        $outputTmp = $this->storage->tempFilePath(
            StorageZone::Proxy, $job->id, "{$job->content_id}_{$profile->variant_key}.{$profile->output_format}"
        );

        $command = [
            (string) config('workflow.tools.ffmpeg'),
            ...$this->compiler->ffmpegAudioArgs($profile, $inputAbs, $outputTmp),
        ];

        $ctx->logger->info($job, 'audio transcode started', ['profile' => $profileCode], implode(' ', $command));
        $ctx->progress->report($job->id, 0.0, 'transcoding audio');

        $result = $this->runner->run(
            $command,
            timeoutSec: max(30, $job->timeout_sec - 30),
            onStdoutLine: function (string $line) use ($ctx, $job, $sourceDurationMs) {
                if ($sourceDurationMs !== null && preg_match('/^out_time_ms=(\d+)/', $line, $m)) {
                    $outMs = ((int) $m[1]) / 1000;
                    $ctx->progress->report($job->id, min(99.0, $outMs / max(1, $sourceDurationMs) * 100), 'transcoding audio');
                }
                $ctx->cancelToken->tick();
            },
        );

        if ($result->exitCode === 124) {
            return JobResult::failure(FailureType::ExternalToolError, 'TOOL_TIMEOUT', $result->stderrTail());
        }

        if (! $result->ok()) {
            return JobResult::failure(FailureType::ExternalToolError, 'FFMPEG_FAILED', $result->stderrTail());
        }

        if (! is_file($outputTmp) || $this->storage->fileSize($outputTmp) === 0) {
            return JobResult::failure(FailureType::ExternalToolError, 'FFMPEG_FAILED', 'audio transcode output missing or empty');
        }

        $outputProbe = $this->prober->probe($outputTmp);

        if ($outputProbe === null) {
            return JobResult::failure(FailureType::ExternalToolError, 'FFMPEG_FAILED', 'audio transcode output probe failed');
        }

        $outputDurationMs = $this->prober->durationMs($outputProbe);

        // 검증: 재생 길이 원본 ±0.5초 (Job Type Def §3.6 성공 조건)
        if ($sourceDurationMs !== null && $outputDurationMs !== null
            && abs($outputDurationMs - $sourceDurationMs) > self::DURATION_TOLERANCE_MS) {
            return JobResult::failure(
                FailureType::ExternalToolError, 'FFMPEG_FAILED',
                "output duration {$outputDurationMs}ms deviates from source {$sourceDurationMs}ms by more than 0.5s",
            );
        }

        $targetRelative = sprintf(
            'proxy/%s/%s/%d_%s.%s',
            now()->format('Y'), now()->format('m'),
            $job->content_id, $profile->code, $profile->output_format,
        );

        $finalAbs = $this->storage->promote(StorageZone::Proxy, $outputTmp, $targetRelative);

        $this->renditions->upsert([
            'content_id' => $job->content_id,
            'media_file_id' => $master->media_file_id,
            'rendition_type' => $profile->rendition_type->value,
            'storage_zone' => StorageZone::Proxy->value,
            'variant_key' => $profile->variant_key,
            'path' => $targetRelative,
            'file_size' => $this->storage->fileSize($finalAbs),
            'checksum' => $this->storage->checksumSha256($finalAbs),
            'mime_type' => 'audio/mp4',
            'duration_ms' => $outputDurationMs,
            'profile_id' => $profile->id,
        ]);

        $ctx->progress->report($job->id, 100.0, 'audio transcoded');

        return JobResult::success([
            'proxy_path' => $targetRelative,
            'profile' => $profile->code,
            'duration_ms' => $outputDurationMs,
        ]);
    }

    private function sourceDurationMs(JobExecutionContext $ctx, string $inputAbs): ?int
    {
        $mediaInfo = $ctx->mediaFile()?->media_info;

        if (is_array($mediaInfo) && isset($mediaInfo['format']['duration'])) {
            return (int) round(((float) $mediaInfo['format']['duration']) * 1000);
        }

        $probe = $this->prober->probe($inputAbs);

        return $probe !== null ? $this->prober->durationMs($probe) : null;
    }
}
