<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClearWorkerErrorRequest;
use App\Http\Requests\Admin\DisableWorkerRequest;
use App\Http\Requests\Admin\EnableWorkerRequest;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Admin\Command\WorkerCommandService;
use App\Services\Workflow\Admin\Query\WorkerQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerController extends Controller
{
    public function index(Request $request, WorkerQueryService $query): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $query->list($request->user())->values(),
        ]);
    }

    public function disable(DisableWorkerRequest $request, WorkflowWorkerAgent $worker, WorkerCommandService $service): JsonResponse
    {
        $result = $service->disable(
            $worker,
            reason: $request->validated('reason'),
            drain: $request->boolean('drain', true),
            actor: $request->user(),
        );

        return response()->json($result->toResponse(), $result->httpStatus);
    }

    public function enable(EnableWorkerRequest $request, WorkflowWorkerAgent $worker, WorkerCommandService $service): JsonResponse
    {
        $result = $service->enable($worker, $request->validated('reason'), $request->user());

        return response()->json($result->toResponse(), $result->httpStatus);
    }

    public function clearError(ClearWorkerErrorRequest $request, WorkflowWorkerAgent $worker, WorkerCommandService $service): JsonResponse
    {
        $result = $service->clearError($worker, $request->validated('reason'), $request->user());

        return response()->json($result->toResponse(), $result->httpStatus);
    }
}
