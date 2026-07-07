<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelJobRequest;
use App\Http\Requests\Admin\ReleaseJobLockRequest;
use App\Http\Requests\Admin\RetryJobBatchRequest;
use App\Http\Requests\Admin\RetryJobRequest;
use App\Http\Requests\Admin\UpdateJobPriorityRequest;
use App\Models\WorkflowJob;
use App\Services\Workflow\Admin\Command\JobCancelService;
use App\Services\Workflow\Admin\Command\JobLockReleaseService;
use App\Services\Workflow\Admin\Command\JobPriorityService;
use App\Services\Workflow\Admin\Command\JobRetryBatchService;
use App\Services\Workflow\Admin\Command\JobRetryService;
use App\Services\Workflow\Admin\Data\ListFilterData;
use App\Services\Workflow\Admin\Query\JobQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    public function index(Request $request, JobQueryService $query): JsonResponse
    {
        $paginator = $query->paginate(
            ListFilterData::fromRequest($request, JobQueryService::ALLOWED_FILTERS, JobQueryService::ALLOWED_SORTS),
            $request->user(),
        );

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, WorkflowJob $job, JobQueryService $query): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $query->detail($job, $request->user()),
        ]);
    }

    public function retry(RetryJobRequest $request, WorkflowJob $job, JobRetryService $service): JsonResponse
    {
        $result = $service->execute(
            $job,
            reason: $request->validated('reason'),
            resetRetryCount: $request->boolean('reset_retry_count', true),
            priority: $request->validated('priority'),
            actor: $request->user(),
        );

        return response()->json($result->toResponse(), $result->httpStatus);
    }

    public function retryBatch(RetryJobBatchRequest $request, JobRetryBatchService $service): JsonResponse
    {
        $result = $service->execute(
            $request->validated('job_ids'),
            $request->validated('reason'),
            $request->user(),
        );

        return response()->json($result->toResponse(), $result->httpStatus);
    }

    public function cancel(CancelJobRequest $request, WorkflowJob $job, JobCancelService $service): JsonResponse
    {
        $result = $service->execute($job, $request->validated('reason'), $request->user());

        return response()->json($result->toResponse(), $result->httpStatus);
    }

    public function releaseLock(ReleaseJobLockRequest $request, WorkflowJob $job, JobLockReleaseService $service): JsonResponse
    {
        $result = $service->execute(
            $job,
            reason: $request->validated('reason'),
            targetStatus: $request->validated('target_status') ?? 'RETRY',
            actor: $request->user(),
        );

        return response()->json($result->toResponse(), $result->httpStatus);
    }

    public function updatePriority(UpdateJobPriorityRequest $request, WorkflowJob $job, JobPriorityService $service): JsonResponse
    {
        $result = $service->execute(
            $job,
            priority: (int) $request->validated('priority'),
            reason: $request->validated('reason'),
            actor: $request->user(),
        );

        return response()->json($result->toResponse(), $result->httpStatus);
    }
}
