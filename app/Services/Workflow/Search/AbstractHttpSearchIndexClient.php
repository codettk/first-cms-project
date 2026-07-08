<?php

namespace App\Services\Workflow\Search;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP REST 기반 실제 검색엔진 클라이언트 공통 구현 (ADR-0005).
 * Elasticsearch/OpenSearch의 문서 API(PUT·GET /{index}/_doc/{id})는 동일하므로
 * 인증 헤더 구성만 driver별 하위 클래스에 위임한다.
 *
 * 예외 메시지에는 credential(비밀번호·api key·host URL의 userinfo)을 포함하지 않는다.
 */
abstract class AbstractHttpSearchIndexClient implements SearchIndexClient
{
    /** @var list<string> */
    private readonly array $hosts;

    /** @param array<string, mixed> $config config('workflow.search') */
    public function __construct(protected readonly array $config)
    {
        $hosts = array_values(array_filter(array_map(
            fn ($host) => rtrim(trim((string) $host), '/'),
            $config['hosts'] ?? [],
        )));

        if ($hosts === []) {
            throw new SearchIndexException('검색엔진 host가 설정되지 않았다 (WORKFLOW_SEARCH_HOSTS)');
        }

        $this->hosts = $hosts;
    }

    /** driver별 인증 헤더 구성 */
    abstract protected function authorize(PendingRequest $request): PendingRequest;

    public function upsert(string $documentId, array $document): void
    {
        $response = $this->request('PUT', $this->documentPath($documentId), $document);

        if ($response->failed()) {
            throw $this->requestFailed('upsert', $documentId, $response);
        }
    }

    public function get(string $documentId): ?array
    {
        $response = $this->request('GET', $this->documentPath($documentId));

        if ($response->notFound()) {
            return null;
        }

        if ($response->failed()) {
            throw $this->requestFailed('get', $documentId, $response);
        }

        $source = $response->json('_source');

        return is_array($source) ? $source : null;
    }

    private function documentPath(string $documentId): string
    {
        $index = (string) ($this->config['index'] ?? 'content_mam');

        return "/{$index}/_doc/".rawurlencode($documentId);
    }

    /** host 목록 순서대로 시도 — 연결 실패 시 다음 host로 failover */
    private function request(string $method, string $path, ?array $body = null): Response
    {
        $lastHost = null;

        foreach ($this->hosts as $host) {
            $lastHost = $host;

            try {
                return $this->pendingRequest()
                    ->send($method, $host.$path, $body === null ? [] : ['json' => $body]);
            } catch (ConnectionException) {
                continue;
            }
        }

        throw new SearchIndexException(sprintf(
            '검색엔진 연결 실패 — host %d개 모두 응답 없음 (마지막 시도: %s)',
            count($this->hosts),
            self::maskUserinfo($lastHost),
        ));
    }

    private function pendingRequest(): PendingRequest
    {
        return $this->authorize(
            Http::acceptJson()
                ->timeout((int) ($this->config['timeout'] ?? 10))
                ->withOptions(['verify' => (bool) ($this->config['verify_ssl'] ?? true)]),
        );
    }

    private function requestFailed(string $operation, string $documentId, Response $response): SearchIndexException
    {
        // 응답 본문은 서버측 오류 설명(mapping 충돌 등) — 요청측 credential은 포함되지 않는다
        return new SearchIndexException(sprintf(
            '색인 %s 실패 — document %s, HTTP %d: %s',
            $operation,
            $documentId,
            $response->status(),
            mb_substr($response->body(), 0, 300),
        ));
    }

    private static function maskUserinfo(?string $host): string
    {
        return preg_replace('#://[^@/]+@#', '://***@', (string) $host);
    }
}
