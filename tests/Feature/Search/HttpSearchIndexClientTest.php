<?php

namespace Tests\Feature\Search;

use App\Services\Workflow\Search\ElasticsearchSearchIndexClient;
use App\Services\Workflow\Search\OpenSearchSearchIndexClient;
use App\Services\Workflow\Search\SearchIndexException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HTTP REST 클라이언트 (ADR-0005) — endpoint/payload/인증 헤더, 실패 응답 예외,
 * host failover, credential 미노출을 Http::fake로 검증한다.
 */
class HttpSearchIndexClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'elasticsearch',
            'index' => 'content_mam',
            'hosts' => ['http://es.local:9200'],
            'username' => null,
            'password' => null,
            'api_key' => null,
            'verify_ssl' => true,
            'timeout' => 10,
        ], $overrides);
    }

    public function test_upsert_puts_document_to_index_endpoint(): void
    {
        Http::fake(['http://es.local:9200/*' => Http::response(['result' => 'created'], 201)]);

        (new ElasticsearchSearchIndexClient($this->config()))
            ->upsert('content-7', ['content_id' => 7, 'title' => '뉴스 A']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'http://es.local:9200/content_mam/_doc/content-7'
            && $r['content_id'] === 7
            && $r['title'] === '뉴스 A');
    }

    public function test_get_returns_source_and_null_on_404(): void
    {
        Http::fake([
            'http://es.local:9200/content_mam/_doc/content-7' => Http::response([
                '_id' => 'content-7',
                '_source' => ['content_id' => 7],
            ]),
            'http://es.local:9200/content_mam/_doc/content-404' => Http::response(['found' => false], 404),
        ]);

        $client = new ElasticsearchSearchIndexClient($this->config());

        $this->assertSame(['content_id' => 7], $client->get('content-7'));
        $this->assertNull($client->get('content-404'));
    }

    public function test_elasticsearch_api_key_takes_precedence_over_basic_auth(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        (new ElasticsearchSearchIndexClient($this->config([
            'api_key' => 'the-api-key',
            'username' => 'elastic',
            'password' => 'pw',
        ])))->upsert('content-1', ['content_id' => 1]);

        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['ApiKey the-api-key']);
    }

    public function test_opensearch_uses_basic_auth_only(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        (new OpenSearchSearchIndexClient($this->config([
            'driver' => 'opensearch',
            'username' => 'admin',
            'password' => 'secret-pw',
            'api_key' => 'ignored-for-opensearch',
        ])))->upsert('content-1', ['content_id' => 1]);

        Http::assertSent(fn (Request $r) => $r->header('Authorization') === ['Basic '.base64_encode('admin:secret-pw')]);
    }

    public function test_failure_response_throws_without_credentials_in_message(): void
    {
        Http::fake(['*' => Http::response(['error' => 'mapper_parsing_exception'], 500)]);

        $client = new ElasticsearchSearchIndexClient($this->config([
            'api_key' => 'super-secret-key',
            'username' => 'elastic',
            'password' => 'super-secret-pw',
        ]));

        try {
            $client->upsert('content-9', ['content_id' => 9]);
            $this->fail('SearchIndexException이 발생해야 한다');
        } catch (SearchIndexException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
            $this->assertStringContainsString('content-9', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-key', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-pw', $e->getMessage());
        }
    }

    public function test_connection_failure_fails_over_to_next_host(): void
    {
        Http::fake([
            'http://es-down.local:9200/*' => fn () => throw new ConnectionException('connection refused'),
            'http://es-up.local:9200/*' => Http::response(['result' => 'created'], 200),
        ]);

        (new ElasticsearchSearchIndexClient($this->config([
            'hosts' => ['http://es-down.local:9200', 'http://es-up.local:9200'],
        ])))->upsert('content-1', ['content_id' => 1]);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://es-up.local:9200/'));
    }

    public function test_all_hosts_down_throws_with_userinfo_masked(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('connection refused')]);

        $client = new ElasticsearchSearchIndexClient($this->config([
            'hosts' => ['https://elastic:secret-pw@es.local:9200'],
        ]));

        try {
            $client->get('content-1');
            $this->fail('SearchIndexException이 발생해야 한다');
        } catch (SearchIndexException $e) {
            $this->assertStringContainsString('://***@es.local', $e->getMessage());
            $this->assertStringNotContainsString('secret-pw', $e->getMessage());
        }
    }
}
