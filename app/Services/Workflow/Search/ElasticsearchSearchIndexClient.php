<?php

namespace App\Services\Workflow\Search;

use Illuminate\Http\Client\PendingRequest;

/**
 * Elasticsearch 8.x 클라이언트 — WORKFLOW_SEARCH_DRIVER=elasticsearch (ADR-0005).
 * 인증: api_key(ApiKey 헤더)가 basic auth보다 우선한다.
 */
class ElasticsearchSearchIndexClient extends AbstractHttpSearchIndexClient
{
    protected function authorize(PendingRequest $request): PendingRequest
    {
        if (! empty($this->config['api_key'])) {
            return $request->withHeader('Authorization', 'ApiKey '.$this->config['api_key']);
        }

        if (! empty($this->config['username'])) {
            return $request->withBasicAuth(
                (string) $this->config['username'],
                (string) ($this->config['password'] ?? ''),
            );
        }

        return $request;
    }
}
