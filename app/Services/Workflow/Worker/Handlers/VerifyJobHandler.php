<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Models\MediaRendition;
use App\Models\WorkflowJob;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\Tools\MediaProber;
use App\Services\Workflow\Worker\Tools\ToolRunner;
use Illuminate\Support\Facades\DB;

/**
 * VERIFY — 존재·크기·checksum·MIME·헤더 파싱 검사 (Worker Agent Spec §9).
 * 손상·비허용 포맷은 PERMANENT — 재시도하지 않는다.
 */
class VerifyJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'VERIFY';

    /** 허용 MIME prefix/목록 — Job Type Def §3.2 */
    private const array ALLOWED_MIME_PREFIXES = ['video/', 'audio/', 'image/'];

    // 판별 불가(octet-stream)는 허용하지 않는다 — 검증 우회 방지
    private const array ALLOWED_MIME_EXACT = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly MediaProber $prober,
        private readonly ToolRunner $runner,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $master = MediaRendition::query()
            ->where('content_id', $job->content_id)
            ->where('rendition_type', 'MASTER')
            ->first();

        if ($master === null) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'MASTER rendition row missing');
        }

        $abs = $this->storage->absolutePath($master->storage_zone, $master->path);

        if (! is_file($abs) || $this->storage->fileSize($abs) === 0) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'master file missing or empty');
        }

        $ctx->progress->report($job->id, 20.0, 'verifying checksum');

        if ($master->checksum !== null && $this->storage->checksumSha256($abs) !== $master->checksum) {
            return JobResult::failure(FailureType::Permanent, 'PERMANENT_CORRUPT', 'master checksum mismatch');
        }

        $ctx->cancelToken->tick();
        $ctx->progress->report($job->id, 50.0, 'detecting mime type');

        $detectedMime = mime_content_type($abs) ?: 'application/octet-stream';

        if (! $this->isAllowedMime($detectedMime)) {
            return JobResult::failure(FailureType::Permanent, 'UNSUPPORTED_FORMAT', "mime not allowed: {$detectedMime}");
        }

        // 헤더 파싱 손상 검사 — 영상/오디오/이미지는 ffprobe, PDF는 pdfinfo (Spec §9 ④)
        if (str_starts_with($detectedMime, 'video/') || str_starts_with($detectedMime, 'audio/')
            || str_starts_with($detectedMime, 'image/')) {
            $ctx->progress->report($job->id, 70.0, 'probing media header');

            if ($this->prober->probe($abs) === null) {
                return JobResult::failure(FailureType::Permanent, 'PERMANENT_CORRUPT', 'media header parse failed');
            }
        } elseif ($detectedMime === 'application/pdf') {
            $ctx->progress->report($job->id, 70.0, 'probing document header');

            $info = $this->runner->run([(string) config('workflow.tools.pdfinfo'), $abs], timeoutSec: 60);

            if (! $info->ok()) {
                return JobResult::failure(FailureType::Permanent, 'PERMANENT_CORRUPT', 'pdf header parse failed');
            }

            // 암호화 문서는 후속 변환이 불가능하다 — 조기 PERMANENT (Transcode Profile Spec §13)
            if (preg_match('/^Encrypted:\s*yes/mi', $info->stdout)) {
                return JobResult::failure(FailureType::Permanent, 'ENCRYPTED_DOC', 'encrypted document is not allowed');
            }
        }

        $mediaFile = $ctx->mediaFile();
        if ($mediaFile !== null) {
            DB::table('media_files')->where('id', $mediaFile->id)
                ->update(['detected_mime' => $detectedMime]);
        }

        $ctx->progress->report($job->id, 100.0, 'verified');

        return JobResult::success(['detected_mime' => $detectedMime, 'verified' => true]);
    }

    private function isAllowedMime(string $mime): bool
    {
        if (in_array($mime, self::ALLOWED_MIME_EXACT, true)) {
            return true;
        }

        foreach (self::ALLOWED_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
