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
        // IMAGE 파이프라인 — Transcode Profile Spec §12 (libvips)
        'vipsthumbnail' => env('WORKFLOW_TOOL_VIPSTHUMBNAIL', 'vipsthumbnail'),
        // DOC 파이프라인 — Transcode Profile Spec §13 (poppler·LibreOffice)
        'pdfinfo' => env('WORKFLOW_TOOL_PDFINFO', 'pdfinfo'),
        'pdftoppm' => env('WORKFLOW_TOOL_PDFTOPPM', 'pdftoppm'),
        'pdftotext' => env('WORKFLOW_TOOL_PDFTOTEXT', 'pdftotext'),
        'soffice' => env('WORKFLOW_TOOL_SOFFICE', 'soffice'),
    ],

    // 검색 색인 driver — dev(cache mock)|elasticsearch|opensearch (ADR-0005).
    // dev는 개발 테스트용만 허용, 운영 게이트는 실제 driver 기준 (Roadmap Phase 5)
    'search' => [
        'driver' => env('WORKFLOW_SEARCH_DRIVER', 'dev'),
        'index' => env('WORKFLOW_SEARCH_INDEX', 'content_mam'),
        'hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('WORKFLOW_SEARCH_HOSTS', '')),
        ))),
        'username' => env('WORKFLOW_SEARCH_USERNAME'),
        'password' => env('WORKFLOW_SEARCH_PASSWORD'),
        'api_key' => env('WORKFLOW_SEARCH_API_KEY'), // Elasticsearch 전용 — basic보다 우선
        'verify_ssl' => filter_var(env('WORKFLOW_SEARCH_VERIFY_SSL', true), FILTER_VALIDATE_BOOL),
        'timeout' => (int) env('WORKFLOW_SEARCH_TIMEOUT', 10),
    ],
];
