<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkflowAlert;
use App\Services\Workflow\Admin\AlertService;
use App\Services\Workflow\Admin\Query\AlertQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 알림 목록·확인 API — Admin API Spec §21 · ADR-0006 (ack는 멱등).
 */
class AlertController extends Controller
{
    public function index(Request $request, AlertQueryService $query): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $query->list($request->query()),
        ]);
    }

    public function ack(Request $request, WorkflowAlert $alert, AlertService $service): JsonResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $acked = $service->acknowledge($alert, $request->user(), $validated['note'] ?? null);

        return response()->json(['success' => true, 'data' => $acked->toItem()]);
    }
}
