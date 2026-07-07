<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Enums\StorageZone;
use App\Models\WorkflowJob;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Storage\RenditionService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;

/**
 * TM — TEMP → MASTER 이동 · checksum 검증 · MASTER rendition 생성 (Worker Agent Spec §9).
 */
class TmJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'TM';

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly RenditionService $renditions,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $mediaFile = $ctx->mediaFile();

        if ($mediaFile === null || $mediaFile->temp_path === null) {
            return JobResult::failure(FailureType::UserFileError, 'TEMP_FILE_MISSING', 'upload temp file is not registered');
        }

        $tempAbs = $this->storage->absolutePath(StorageZone::Temp, $mediaFile->temp_path);

        if (! is_file($tempAbs)) {
            return JobResult::failure(FailureType::UserFileError, 'TEMP_FILE_MISSING', "temp file missing: {$mediaFile->temp_path}");
        }

        if ($this->storage->fileSize($tempAbs) !== (int) $mediaFile->file_size) {
            return JobResult::failure(FailureType::UserFileError, 'SIZE_MISMATCH', 'uploaded size differs from registered file_size');
        }

        $ctx->progress->report($job->id, 10.0, 'copying to master zone');

        // 임시 이름으로 복사 → checksum 검증 → atomic rename (부분 쓰기 노출 방지)
        $workPath = $this->storage->tempFilePath(StorageZone::Master, $job->id, "{$job->content_id}.{$mediaFile->ext}");

        if (! copy($tempAbs, $workPath)) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'copy to master work dir failed');
        }

        $ctx->cancelToken->tick();
        $ctx->progress->report($job->id, 60.0, 'verifying checksum');

        $checksum = $this->storage->checksumSha256($workPath);

        if ($checksum !== $mediaFile->checksum) {
            return JobResult::failure(FailureType::StorageError, 'CHECKSUM_MISMATCH', 'checksum differs from upload-time value');
        }

        $targetRelative = sprintf(
            'master/%s/%s/%d_%s.%s',
            now()->format('Y'), now()->format('m'),
            $job->content_id, bin2hex(random_bytes(4)), $mediaFile->ext,
        );

        $this->storage->promoteToMaster($workPath, $targetRelative);

        $this->renditions->upsert([
            'content_id' => $job->content_id,
            'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER',
            'storage_zone' => StorageZone::Master->value,
            'path' => $targetRelative,
            'file_size' => (int) $mediaFile->file_size,
            'checksum' => $checksum,
            'mime_type' => $mediaFile->detected_mime,
        ]);

        $ctx->progress->report($job->id, 100.0, 'master stored');

        return JobResult::success([
            'master_path' => $targetRelative,
            'checksum' => $checksum,
            'file_size' => (int) $mediaFile->file_size,
        ]);
    }
}
