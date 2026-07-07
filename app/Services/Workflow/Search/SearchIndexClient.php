<?php

namespace App\Services\Workflow\Search;

/**
 * 검색엔진 클라이언트 계약 — Worker Agent Spec §9 INDEX.
 * 색인 실패는 SEARCH_ENGINE_ERROR로 분류되어 재시도된다.
 */
interface SearchIndexClient
{
    /** @param array<string, mixed> $document */
    public function upsert(string $documentId, array $document): void;

    /** 색인 후 조회 검증용 — 없으면 null @return array<string, mixed>|null */
    public function get(string $documentId): ?array;
}
