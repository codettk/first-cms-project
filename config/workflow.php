<?php

/**
 * Workflow 스토리지 zone 설정 — Worker Agent Spec §15·§17.
 * 운영에서는 zone별 마운트 경로를 env로 지정한다 (MASTER는 TM Worker 외 read-only 마운트 권장).
 */
return [
    'storage' => [
        'root' => env('WORKFLOW_STORAGE_ROOT', storage_path('app/workflow')),

        'zones' => [
            'TEMP' => env('WORKFLOW_STORAGE_TEMP'),
            'MASTER' => env('WORKFLOW_STORAGE_MASTER'),
            'PROXY' => env('WORKFLOW_STORAGE_PROXY'),
            'THUMBNAIL' => env('WORKFLOW_STORAGE_THUMBNAIL'),
            'CATALOG' => env('WORKFLOW_STORAGE_CATALOG'),
            'DOCUMENT' => env('WORKFLOW_STORAGE_DOCUMENT'),
            'ARCHIVE' => env('WORKFLOW_STORAGE_ARCHIVE'),
        ],
    ],

    'tools' => [
        'ffmpeg' => env('WORKFLOW_TOOL_FFMPEG', 'ffmpeg'),
        'ffprobe' => env('WORKFLOW_TOOL_FFPROBE', 'ffprobe'),
    ],
];
