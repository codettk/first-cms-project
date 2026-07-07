<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Enums\StorageZone;
use App\Models\MediaRendition;
use App\Models\WorkflowJob;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Storage\ProfileCompiler;
use App\Services\Workflow\Storage\RenditionService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\Tools\ToolRunner;

/**
 * CA — 대표 썸네일 1건 + 카탈로그 N건 (Worker Agent Spec §9).
 * 입력은 Proxy(Master 접근 금지). 대표 실패는 실패, 카탈로그 부분 실패는 SUCCESS + WARN.
 */
class CatalogJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'CA';

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly RenditionService $renditions,
        private readonly ProfileCompiler $compiler,
        private readonly ToolRunner $runner,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $proxy = MediaRendition::query()
            ->where('content_id', $job->content_id)
            ->where('rendition_type', 'PROXY_VIDEO')
            ->first();

        if ($proxy === null) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'PROXY_VIDEO rendition missing — CA input is proxy only');
        }

        $inputAbs = $this->storage->absolutePath($proxy->storage_zone, $proxy->path);

        if (! is_file($inputAbs)) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'proxy file missing on storage');
        }

        $durationMs = $proxy->duration_ms !== null ? (int) $proxy->duration_ms : null;

        // ── 대표 썸네일 (THUMBNAIL_DEFAULT: 5초 프레임, 짧으면 중간)
        $thumbProfile = $this->compiler->loadByCode('THUMBNAIL_DEFAULT');
        $seekSec = (float) (($thumbProfile->params['video_seek_sec'] ?? 5));

        if ($durationMs !== null && $durationMs / 1000 <= $seekSec) {
            $seekSec = $durationMs / 2000; // short video → 중간 프레임
        }

        $ctx->progress->report($job->id, 10.0, 'extracting thumbnail');

        $thumbTmp = $this->storage->tempFilePath(StorageZone::Thumbnail, $job->id, "{$job->content_id}_thumb.webp");
        $thumbResult = $this->runner->run(
            [(string) config('workflow.tools.ffmpeg'), ...$this->compiler->ffmpegFrameArgs($thumbProfile, $inputAbs, $thumbTmp, $seekSec)],
            timeoutSec: 120,
        );

        if (! $thumbResult->ok() || ! is_file($thumbTmp) || $this->storage->fileSize($thumbTmp) === 0) {
            // 대표 1건 실패는 job 실패 (Spec §9 CA)
            return JobResult::failure(FailureType::ExternalToolError, 'FFMPEG_FAILED', 'thumbnail extraction failed: '.$thumbResult->stderrTail());
        }

        $thumbRelative = sprintf('thumb/%s/%s/%d.webp', now()->format('Y'), now()->format('m'), $job->content_id);
        $thumbAbs = $this->storage->promote(StorageZone::Thumbnail, $thumbTmp, $thumbRelative);

        $this->renditions->upsert([
            'content_id' => $job->content_id,
            'media_file_id' => $proxy->media_file_id,
            'rendition_type' => 'THUMBNAIL',
            'storage_zone' => StorageZone::Thumbnail->value,
            'variant_key' => $thumbProfile->variant_key,
            'path' => $thumbRelative,
            'file_size' => $this->storage->fileSize($thumbAbs),
            'checksum' => $this->storage->checksumSha256($thumbAbs),
            'mime_type' => 'image/webp',
            'profile_id' => $thumbProfile->id,
        ]);

        $ctx->cancelToken->tick();

        // ── 카탈로그 N건 (config timecodes — 부분 실패는 WARN 후 계속)
        $catalogProfile = $this->compiler->loadByCode('CATALOG_DEFAULT');
        $timecodes = $this->resolveTimecodes($ctx, $catalogProfile->params ?? [], $durationMs);

        $created = 0;
        $failed = 0;

        foreach ($timecodes as $i => $timecodeMs) {
            $ctx->progress->report($job->id, 30.0 + 65.0 * ($i + 1) / max(1, count($timecodes)), 'extracting catalog frames');

            $catalogTmp = $this->storage->tempFilePath(StorageZone::Catalog, $job->id, "{$job->content_id}_{$timecodeMs}.webp");
            $frameResult = $this->runner->run(
                [(string) config('workflow.tools.ffmpeg'), ...$this->compiler->ffmpegFrameArgs($catalogProfile, $inputAbs, $catalogTmp, $timecodeMs / 1000)],
                timeoutSec: 120,
            );

            if (! $frameResult->ok() || ! is_file($catalogTmp) || $this->storage->fileSize($catalogTmp) === 0) {
                $failed++;
                $ctx->logger->warn($job, "catalog frame failed at {$timecodeMs}ms", ['stderr' => $frameResult->stderrTail(5)]);

                continue;
            }

            $catalogRelative = sprintf('catalog/%s/%s/%d_%d.webp', now()->format('Y'), now()->format('m'), $job->content_id, $timecodeMs);
            $catalogAbs = $this->storage->promote(StorageZone::Catalog, $catalogTmp, $catalogRelative);

            $this->renditions->upsert([
                'content_id' => $job->content_id,
                'media_file_id' => $proxy->media_file_id,
                'rendition_type' => 'CATALOG',
                'storage_zone' => StorageZone::Catalog->value,
                'variant_key' => $catalogProfile->variant_key,
                'path' => $catalogRelative,
                'file_size' => $this->storage->fileSize($catalogAbs),
                'checksum' => $this->storage->checksumSha256($catalogAbs),
                'mime_type' => 'image/webp',
                'timecode_ms' => $timecodeMs,
                'profile_id' => $catalogProfile->id,
            ]);
            $created++;

            $ctx->cancelToken->tick();
        }

        $ctx->progress->report($job->id, 100.0, 'catalog complete');

        return JobResult::success([
            'thumbnail' => $thumbRelative,
            'catalog_created' => $created,
            'catalog_failed' => $failed,
        ]);
    }

    /**
     * job payload timecodes(ms) 우선, 없으면 프로파일 timecodes_sec.
     * duration을 넘는 타임코드는 제외한다.
     *
     * @param array<string, mixed> $profileParams
     * @return list<int>
     */
    private function resolveTimecodes(JobExecutionContext $ctx, array $profileParams, ?int $durationMs): array
    {
        $timecodes = $ctx->payload()['timecodes']
            ?? array_map(fn ($sec) => $sec * 1000, $profileParams['timecodes_sec'] ?? [5, 10, 20]);

        $maxCount = (int) ($profileParams['max_count'] ?? 20);

        $filtered = array_values(array_filter(
            array_map('intval', $timecodes),
            fn (int $ms) => $durationMs === null || $ms < $durationMs,
        ));

        return array_slice($filtered, 0, $maxCount);
    }
}
