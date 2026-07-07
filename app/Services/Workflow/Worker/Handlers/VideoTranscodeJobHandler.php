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
 * TC — 720p proxy 생성 · 진행률 보고 · PROXY_VIDEO rendition upsert (Worker Agent Spec §9).
 * 출력은 .tmp에 쓰고 검증(존재·duration ±1s) 후 atomic rename.
 */
class VideoTranscodeJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'TC';

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
            // 운영 설정 오류 — 재시도하지 않고 알림 대상 (Transcode Profile Spec §15)
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
            ...$this->compiler->ffmpegVideoArgs($profile, $inputAbs, $outputTmp),
        ];

        $ctx->logger->info($job, 'transcode started', ['profile' => $profileCode], implode(' ', $command));
        $ctx->progress->report($job->id, 0.0, 'transcoding');

        $result = $this->runner->run(
            $command,
            timeoutSec: max(30, $job->timeout_sec - 30), // 정리 여유 30초 (Spec §10)
            onStdoutLine: function (string $line) use ($ctx, $job, $sourceDurationMs) {
                // ffmpeg -progress pipe:1 — out_time_ms는 마이크로초 단위
                if ($sourceDurationMs !== null && preg_match('/^out_time_ms=(\d+)/', $line, $m)) {
                    $outMs = ((int) $m[1]) / 1000;
                    $percent = min(99.0, $outMs / max(1, $sourceDurationMs) * 100);
                    $ctx->progress->report($job->id, $percent, 'transcoding', $this->eta($job, $percent));
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

        // 검증: 파일 존재·크기>0 · duration 원본 ±1s (Transcode Profile Spec §16)
        if (! is_file($outputTmp) || $this->storage->fileSize($outputTmp) === 0) {
            return JobResult::failure(FailureType::ExternalToolError, 'FFMPEG_FAILED', 'transcode output missing or empty');
        }

        $outputProbe = $this->prober->probe($outputTmp);

        if ($outputProbe === null) {
            return JobResult::failure(FailureType::ExternalToolError, 'FFMPEG_FAILED', 'transcode output probe failed');
        }

        $outputDurationMs = $this->prober->durationMs($outputProbe);

        if ($sourceDurationMs !== null && $outputDurationMs !== null
            && abs($outputDurationMs - $sourceDurationMs) > 1000) {
            return JobResult::failure(
                FailureType::ExternalToolError, 'FFMPEG_FAILED',
                "output duration {$outputDurationMs}ms deviates from source {$sourceDurationMs}ms by more than 1s",
            );
        }

        $targetRelative = sprintf(
            'proxy/%s/%s/%d_%s.%s',
            now()->format('Y'), now()->format('m'),
            $job->content_id, $profile->code, $profile->output_format,
        );

        $finalAbs = $this->storage->promote(StorageZone::Proxy, $outputTmp, $targetRelative);

        $video = $this->prober->primaryVideoStream($outputProbe);

        $this->renditions->upsert([
            'content_id' => $job->content_id,
            'media_file_id' => $master->media_file_id,
            'rendition_type' => $profile->rendition_type->value,
            'storage_zone' => StorageZone::Proxy->value,
            'variant_key' => $profile->variant_key,
            'path' => $targetRelative,
            'file_size' => $this->storage->fileSize($finalAbs),
            'checksum' => $this->storage->checksumSha256($finalAbs),
            'mime_type' => 'video/mp4',
            'width' => $video['width'],
            'height' => $video['height'],
            'duration_ms' => $outputDurationMs,
            'profile_id' => $profile->id,
        ]);

        $ctx->progress->report($job->id, 100.0, 'transcoded');

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

    private function eta(WorkflowJob $job, float $percent): ?int
    {
        if ($percent <= 0 || $job->started_at === null) {
            return null;
        }

        $elapsed = max(1, now()->diffInSeconds($job->started_at, true));

        return (int) round($elapsed * (100 - $percent) / $percent);
    }
}
