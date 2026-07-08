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
 * IMAGE_TC — 웹 표출용 프록시 이미지 생성 (Job Type Def §3.5 · Transcode Profile Spec §12).
 * vipsthumbnail: EXIF Orientation 픽셀 적용·GPS 등 메타 strip·sRGB 변환·최대 변 축소만.
 * 출력은 .tmp에 쓰고 검증(존재·디코딩 가능·최대 변 이내) 후 atomic rename.
 */
class ImageTranscodeJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'IMAGE_TC';

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

        $outputTmp = $this->storage->tempFilePath(
            StorageZone::Proxy, $job->id, "{$job->content_id}_{$profile->variant_key}.{$profile->output_format}"
        );

        $command = [
            (string) config('workflow.tools.vipsthumbnail'),
            ...$this->compiler->vipsThumbnailArgs($profile, $inputAbs, $outputTmp),
        ];

        $ctx->logger->info($job, 'image transcode started', ['profile' => $profileCode], implode(' ', $command));
        $ctx->progress->report($job->id, 10.0, 'transcoding image');

        $result = $this->runner->run($command, timeoutSec: max(30, $job->timeout_sec - 30));

        if ($result->exitCode === 124) {
            return JobResult::failure(FailureType::ExternalToolError, 'TOOL_TIMEOUT', $result->stderrTail());
        }

        if (! $result->ok()) {
            return JobResult::failure(FailureType::ExternalToolError, 'VIPS_FAILED', $result->stderrTail());
        }

        if (! is_file($outputTmp) || $this->storage->fileSize($outputTmp) === 0) {
            return JobResult::failure(FailureType::ExternalToolError, 'VIPS_FAILED', 'image transcode output missing or empty');
        }

        // 검증: 디코딩 가능 + 지정 최대 변 이내 (Job Type Def §3.5 성공 조건)
        $outputProbe = $this->prober->probe($outputTmp);

        if ($outputProbe === null) {
            return JobResult::failure(FailureType::ExternalToolError, 'VIPS_FAILED', 'image transcode output probe failed');
        }

        $image = $this->prober->primaryVideoStream($outputProbe);
        $maxSide = max((int) ($profile->width ?? 0), (int) ($profile->height ?? 0));

        if ($maxSide > 0 && (($image['width'] ?? 0) > $maxSide || ($image['height'] ?? 0) > $maxSide)) {
            return JobResult::failure(
                FailureType::ExternalToolError, 'VIPS_FAILED',
                "output {$image['width']}x{$image['height']} exceeds max side {$maxSide}",
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
            'mime_type' => "image/{$profile->output_format}",
            'width' => $image['width'],
            'height' => $image['height'],
            'profile_id' => $profile->id,
        ]);

        $ctx->progress->report($job->id, 100.0, 'image transcoded');

        return JobResult::success([
            'proxy_path' => $targetRelative,
            'profile' => $profile->code,
            'width' => $image['width'],
            'height' => $image['height'],
        ]);
    }
}
