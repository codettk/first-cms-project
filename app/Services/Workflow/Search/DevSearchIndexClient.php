<?php

namespace App\Services\Workflow\Search;

use Illuminate\Support\Facades\Cache;

/**
 * 개발/테스트 전용 Mock 색인 클라이언트 — cache 저장.
 *
 * Roadmap Phase 5: "Mock INDEX는 개발 환경 테스트용으로만 허용 —
 * 운영 MVP 게이트는 실제 INDEX 성공 기준". 운영 배포 전 실제 검색엔진
 * 클라이언트 구현으로 교체하고 WORKFLOW_SEARCH_DRIVER를 전환해야 한다.
 */
class DevSearchIndexClient implements SearchIndexClient
{
    private const string PREFIX = 'workflow:search-index:';

    public function upsert(string $documentId, array $document): void
    {
        Cache::forever(self::PREFIX.$documentId, $document);
    }

    public function get(string $documentId): ?array
    {
        return Cache::get(self::PREFIX.$documentId);
    }
}
