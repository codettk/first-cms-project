<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelContentRequest;
use App\Http\Requests\Admin\ReprocessContentRequest;
use App\Models\Content;
use App\Services\Workflow\Admin\Command\ContentCancelService;
use App\Services\Workflow\Admin\Command\ContentReprocessService;
use App\Services\Workflow\Admin\Data\ListFilterData;
use App\Services\Workflow\Admin\Query\ContentQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentWorkflowController extends Controller
{
    public function index(Request $request, ContentQueryService $query): JsonResponse
    {
        $paginator = $query->paginate(
            ListFilterData::fromRequest($request, ContentQueryService::ALLOWED_FILTERS, ContentQueryService::ALLOWED_SORTS),
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

    public function show(Request $request, Content $content, ContentQueryService $query): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $query->detail($content, $request->user()),
        ]);
    }

    public function reprocess(ReprocessContentRequest $request, Content $content, ContentReprocessService $service): JsonResponse
    {
        $result = $service->execute(
            $content,
            mode: $request->validated('mode'),
            reason: $request->validated('reason'),
            priority: $request->validated('priority'),
            actor: $request->user(),
        );

        return response()->json($result->toResponse(), $result->httpStatus);
    }

    public function cancel(CancelContentRequest $request, Content $content, ContentCancelService $service): JsonResponse
    {
        $result = $service->execute($content, $request->validated('reason'), $request->user());

        return response()->json($result->toResponse(), $result->httpStatus);
    }
}
