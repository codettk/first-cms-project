<?php

namespace Tests\Feature\Search;

use App\Services\Workflow\Search\DevSearchIndexClient;
use App\Services\Workflow\Search\ElasticsearchSearchIndexClient;
use App\Services\Workflow\Search\OpenSearchSearchIndexClient;
use App\Services\Workflow\Search\SearchIndexClient;
use App\Services\Workflow\Search\SearchIndexException;
use Tests\TestCase;

/**
 * 검색 색인 driver 바인딩 (ADR-0005) — env/config로 dev·elasticsearch·opensearch 선택,
 * 미지원 driver·host 미설정은 컨테이너 해석 시점에 명확히 실패한다.
 */
class SearchIndexClientBindingTest extends TestCase
{
    public function test_dev_driver_is_default_and_binds_dev_client(): void
    {
        $this->assertSame('dev', config('workflow.search.driver'));
        $this->assertInstanceOf(DevSearchIndexClient::class, app(SearchIndexClient::class));
    }

    public function test_elasticsearch_driver_binds_elasticsearch_client(): void
    {
        config([
            'workflow.search.driver' => 'elasticsearch',
            'workflow.search.hosts' => ['http://es.local:9200'],
        ]);

        $this->assertInstanceOf(ElasticsearchSearchIndexClient::class, app(SearchIndexClient::class));
    }

    public function test_opensearch_driver_binds_opensearch_client(): void
    {
        config([
            'workflow.search.driver' => 'opensearch',
            'workflow.search.hosts' => ['http://os.local:9200'],
        ]);

        $this->assertInstanceOf(OpenSearchSearchIndexClient::class, app(SearchIndexClient::class));
    }

    public function test_unsupported_driver_throws(): void
    {
        config(['workflow.search.driver' => 'meilisearch']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('meilisearch');

        app(SearchIndexClient::class);
    }

    public function test_real_driver_without_hosts_throws(): void
    {
        config([
            'workflow.search.driver' => 'elasticsearch',
            'workflow.search.hosts' => [],
        ]);

        $this->expectException(SearchIndexException::class);
        $this->expectExceptionMessage('WORKFLOW_SEARCH_HOSTS');

        app(SearchIndexClient::class);
    }
}
