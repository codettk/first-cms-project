<?php

use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\ContentWorkflowController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\JobController;
use App\Http\Controllers\Admin\WorkerController;
use Illuminate\Support\Facades\Route;

/**
 * Admin Workflow API — Controller Service Spec §3.
 * audit.context = 전 요청 actor/ip/UA 수집만 · audit.write = 조작 라우트만(저장 경로 활성).
 * GET은 admin_audit_logs 저장 대상이 아니다 (Revision Checklist §5).
 * Alerts API는 ADR-0006으로 영속화·구현됨 (ADR-0002 개정).
 */
Route::prefix('admin/workflows')
    ->middleware(['auth', \Illuminate\Routing\Middleware\SubstituteBindings::class, 'audit.context'])
    ->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->can('workflow.view');

        Route::get('contents', [ContentWorkflowController::class, 'index'])->can('workflow.view');
        Route::get('contents/{content}', [ContentWorkflowController::class, 'show'])->can('workflow.view');
        Route::post('contents/{content}/reprocess', [ContentWorkflowController::class, 'reprocess'])
            ->middleware('audit.write')->can('workflow.reprocess');
        Route::post('contents/{content}/cancel', [ContentWorkflowController::class, 'cancel'])
            ->middleware('audit.write')->can('workflow.cancel');

        Route::get('jobs', [JobController::class, 'index'])->can('workflow.view');
        // retry-batch는 {job} 라우트보다 먼저 선언 (라우트 충돌 방지 — Revision Checklist §9)
        Route::post('jobs/retry-batch', [JobController::class, 'retryBatch'])
            ->middleware('audit.write')->can('workflow.retry');
        Route::get('jobs/{job}', [JobController::class, 'show'])->can('workflow.logs.view');
        Route::post('jobs/{job}/retry', [JobController::class, 'retry'])
            ->middleware('audit.write')->can('workflow.retry');
        Route::post('jobs/{job}/cancel', [JobController::class, 'cancel'])
            ->middleware('audit.write')->can('workflow.cancel');
        Route::post('jobs/{job}/release-lock', [JobController::class, 'releaseLock'])
            ->middleware('audit.write')->can('workflow.release_lock');
        Route::patch('jobs/{job}/priority', [JobController::class, 'updatePriority'])
            ->middleware('audit.write')->can('workflow.retry');

        Route::get('alerts', [AlertController::class, 'index'])->can('workflow.view');
        Route::post('alerts/{alert}/ack', [AlertController::class, 'ack'])
            ->middleware('audit.write')->can('workflow.view');

        Route::get('workers', [WorkerController::class, 'index'])->can('workflow.view');
        Route::post('workers/{worker}/disable', [WorkerController::class, 'disable'])
            ->middleware('audit.write')->can('workflow.worker.manage');
        Route::post('workers/{worker}/enable', [WorkerController::class, 'enable'])
            ->middleware('audit.write')->can('workflow.worker.manage');
        Route::post('workers/{worker}/clear-error', [WorkerController::class, 'clearError'])
            ->middleware('audit.write')->can('workflow.worker.manage');
    });
