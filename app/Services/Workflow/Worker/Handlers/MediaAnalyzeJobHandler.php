<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Models\MediaRendition;
use App\Models\WorkflowJob;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\Tools\MediaProber;
use Illuminate\Support\Facades\DB;

/**
 * MA — media_info 추출 · media_type 확정 · contents.media_type 갱신 (Worker Agent Spec §9).
 */
class MediaAnalyzeJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'MA';

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly MediaProber $prober,
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

        $ctx->progress->report($job->id, 30.0, 'probing media');

        $probe = $this->prober->probe($abs, timeoutSec: max(30, $job->timeout_sec - 30));

        if ($probe === null) {
            return JobResult::failure(FailureType::Permanent, 'MEDIA_TYPE_UNDETERMINED', 'media probe failed');
        }

        $mediaType = $this->prober->detectMediaType($probe);

        if ($mediaType === null) {
            return JobResult::failure(FailureType::Permanent, 'MEDIA_TYPE_UNDETERMINED', 'cannot determine media type from streams');
        }

        $durationMs = $this->prober->durationMs($probe);
        $video = $this->prober->primaryVideoStream($probe);

        // VIDEO 필수 필드 assert — 코덱·해상도·길이 (Spec §9 MA)
        if ($mediaType === 'VIDEO'
            && ($video['codec'] === null || $video['width'] === null || $video['height'] === null || $durationMs === null)) {
            return JobResult::failure(FailureType::Permanent, 'PERMANENT_CORRUPT', 'required video fields missing (codec/resolution/duration)');
        }

        $ctx->cancelToken->tick();
        $ctx->progress->report($job->id, 80.0, 'saving media info');

        $mediaFile = $ctx->mediaFile();
        if ($mediaFile !== null) {
            DB::table('media_files')->where('id', $mediaFile->id)
                ->update(['media_info' => json_encode($probe)]);
        }

        // media_type은 status가 아니다 — 직접 갱신 (CHECK: VIDEO/IMAGE/AUDIO/DOC, COMMON 불가)
        DB::table('contents')->where('id', $job->content_id)
            ->update(['media_type' => $mediaType]);

        $ctx->progress->report($job->id, 100.0, 'analyzed');

        return JobResult::success([
            'media_type' => $mediaType,
            'duration_ms' => $durationMs,
            'width' => $video['width'],
            'height' => $video['height'],
            'codec' => $video['codec'],
        ]);
    }
}
