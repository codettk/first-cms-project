<?php

namespace App\Services\Workflow\Admin\Command;

use App\Exceptions\WorkflowException;
use App\Models\User;
use App\Models\WorkflowJob;
use App\Services\Workflow\Admin\AuditLogger;
use App\Services\Workflow\Admin\Data\CommandResult;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 일괄 재시도 — job별 개별 트랜잭션(부분 성공 허용), audit는 배치 1건 + 상세 payload
 * (Controller Service Spec §10·§16).
 */
class JobRetryBatchService
{
    public function __construct(
        private readonly JobRetryService $retry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param list<int> $jobIds
     */
    public function execute(array $jobIds, string $reason, User $actor): CommandResult
    {
        $results = [];

        foreach ($jobIds as $jobId) {
            try {
                $job = WorkflowJob::query()->findOrFail($jobId);
                $this->retry->execute($job, $reason, resetRetryCount: true, priority: null, actor: $actor);
                $results[] = ['job_id' => $jobId, 'success' => true, 'error_code' => null];
            } catch (WorkflowException $e) {
                $results[] = ['job_id' => $jobId, 'success' => false, 'error_code' => $e->errorCode()];
            } catch (ModelNotFoundException) {
                $results[] = ['job_id' => $jobId, 'success' => false, 'error_code' => 'NOT_FOUND'];
            } catch (Throwable) {
                $results[] = ['job_id' => $jobId, 'success' => false, 'error_code' => 'INTERNAL_ERROR'];
            }
        }

        $succeeded = count(array_filter($results, fn ($r) => $r['success']));

        // 배치 감사 1건 — 대상은 첫 job (상세는 payload에)
        $firstJob = WorkflowJob::query()->find($jobIds[0]);
        if ($firstJob !== null) {
            DB::transaction(fn () => $this->audit->log(
                'job.retry_batch', $firstJob, null, null,
                ['job_ids' => $jobIds, 'results' => $results], $reason,
            ));
        }

        return CommandResult::ok([
            'results' => $results,
            'succeeded' => $succeeded,
            'failed' => count($results) - $succeeded,
        ]);
    }
}
