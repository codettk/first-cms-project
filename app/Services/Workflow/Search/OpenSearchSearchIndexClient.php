<?php

namespace App\Services\Workflow\Search;

use Illuminate\Http\Client\PendingRequest;

/**
 * OpenSearch 2.x 클라이언트 — WORKFLOW_SEARCH_DRIVER=opensearch (ADR-0005).
 * 인증: basic auth만 — ApiKey 헤더는 Elasticsearch 전용이라 api_key 설정을 사용하지 않는다.
 */
class OpenSearchSearchIndexClient extends AbstractHttpSearchIndexClient
{
    protected function authorize(PendingRequest $request): PendingRequest
    {
        if (! empty($this->config['username'])) {
            return $request->withBasicAuth(
                (string) $this->config['username'],
                (string) ($this->config['password'] ?? ''),
            );
        }

        return $request;
    }
}
