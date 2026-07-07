<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Workflow\Admin\Query\DashboardQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardQueryService $query): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $query->aggregate(refresh: $request->boolean('refresh')),
        ]);
    }
}
