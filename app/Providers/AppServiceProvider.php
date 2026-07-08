<?php

namespace App\Providers;

use App\Services\Workflow\Search\DevSearchIndexClient;
use App\Services\Workflow\Search\ElasticsearchSearchIndexClient;
use App\Services\Workflow\Search\OpenSearchSearchIndexClient;
use App\Services\Workflow\Search\SearchIndexClient;
use App\Services\Workflow\Worker\HandlerRegistry;
use App\Services\Workflow\Worker\Handlers\CatalogJobHandler;
use App\Services\Workflow\Worker\Handlers\CleanupJobHandler;
use App\Services\Workflow\Worker\Handlers\IndexJobHandler;
use App\Services\Workflow\Worker\Handlers\MediaAnalyzeJobHandler;
use App\Services\Workflow\Worker\Handlers\PublishJobHandler;
use App\Services\Workflow\Worker\Handlers\TmJobHandler;
use App\Services\Workflow\Worker\Handlers\VerifyJobHandler;
use App\Services\Workflow\Worker\Handlers\VideoTranscodeJobHandler;
use App\Services\Workflow\Worker\Tools\SymfonyProcessToolRunner;
use App\Services\Workflow\Worker\Tools\ToolRunner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * MVP Handler 8종 — OCR·STT·AI_ANALYSIS·HLS·WAVEFORM·IMAGE_TC·AUDIO_TC·
     * DOC_PREVIEW·TEXT_EXTRACT는 M6 확장으로 등록하지 않는다 (MVP Scope Git Strategy §1).
     */
    private const array MVP_HANDLERS = [
        TmJobHandler::class,
        VerifyJobHandler::class,
        MediaAnalyzeJobHandler::class,
        VideoTranscodeJobHandler::class,
        CatalogJobHandler::class,
        IndexJobHandler::class,
        PublishJobHandler::class,
        CleanupJobHandler::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 요청 스코프 감사 컨텍스트 — audit.context/write 미들웨어와 AuditLogger가 공유
        $this->app->scoped(\App\Services\Workflow\Admin\AuditContext::class);

        $this->app->bind(ToolRunner::class, SymfonyProcessToolRunner::class);

        // 검색 색인 driver 선택 (ADR-0005) — dev는 개발 테스트용만 허용,
        // 운영 게이트는 실제 driver(elasticsearch/opensearch) 기준 (Roadmap Phase 5)
        $this->app->bind(SearchIndexClient::class, function ($app) {
            $config = (array) $app['config']->get('workflow.search');
            $driver = $config['driver'] ?? 'dev';

            return match ($driver) {
                'dev' => new DevSearchIndexClient,
                'elasticsearch' => new ElasticsearchSearchIndexClient($config),
                'opensearch' => new OpenSearchSearchIndexClient($config),
                default => throw new \InvalidArgumentException(
                    "지원하지 않는 workflow.search.driver [{$driver}] — dev|elasticsearch|opensearch만 허용",
                ),
            };
        });

        // Handler 등록의 단일 지점 — Worker Agent Spec §2
        $this->app->singleton(HandlerRegistry::class, function ($app) {
            $registry = new HandlerRegistry;

            foreach (self::MVP_HANDLERS as $handlerClass) {
                $registry->register($app->make($handlerClass));
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // permission 문자열 Gate 정의 단일 지점 — HIGH는 hasDirectPermission만 (ADR-0004)
        \App\Services\Workflow\Admin\WorkflowPermissionService::registerGates();
    }
}
