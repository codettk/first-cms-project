<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\StorageZone;
use App\Models\WorkflowJob;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use Illuminate\Support\Facades\DB;

/**
 * CLEANUP — temp 파일·중간 산출물(.tmp)·progress 행 정리 (Worker Agent Spec §9).
 * MASTER zone은 어떤 경로도 삭제하지 않는다(MediaStorageService 가드가 이중 차단).
 */
class CleanupJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'CLEANUP';

    public function __construct(private readonly MediaStorageService $storage) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $content = $ctx->content();
        $removedTempFiles = 0;

        // ① 업로드 temp 삭제 (없으면 성공 간주) + temp_path=NULL
        foreach ($content->mediaFiles()->whereNotNull('temp_path')->get() as $mediaFile) {
            $this->storage->delete(StorageZone::Temp, $mediaFile->temp_path);
            DB::table('media_files')->where('id', $mediaFile->id)->update(['temp_path' => null]);
            $removedTempFiles++;
        }

        $ctx->progress->report($job->id, 50.0, 'cleaning scratch space');

        // ② 인스턴스 전 job의 .tmp 작업 디렉터리 + ③ progress 잔여 행 정리
        $siblingJobIds = WorkflowJob::query()
            ->where('instance_id', $job->instance_id)
            ->pluck('id');

        foreach ($siblingJobIds as $jobId) {
            $this->storage->cleanTempWorkDirs((int) $jobId);
        }

        DB::table('workflow_job_progresses')
            ->whereIn('job_id', $siblingJobIds)
            ->where('job_id', '<>', $job->id) // 자신의 행은 finalize에서 삭제된다
            ->delete();

        $ctx->progress->report($job->id, 100.0, 'cleaned');

        return JobResult::success([
            'temp_files_removed' => $removedTempFiles,
            'scratch_dirs_cleaned' => $siblingJobIds->count(),
        ]);
    }
}
