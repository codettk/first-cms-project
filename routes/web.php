<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 워크플로우 운영 콘솔 (MVP 4화면) — 데이터는 /admin/workflows JSON API만 사용
Route::get('/admin/workflow-console', function () {
    return view('admin.workflow.console');
})->middleware('auth')->can('workflow.view')->name('workflow.console');
