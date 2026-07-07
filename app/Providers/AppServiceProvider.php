<?php

namespace App\Providers;

use App\Services\Workflow\Search\DevSearchIndexClient;
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
        $this->app->bind(ToolRunner::class, SymfonyProcessToolRunner::class);

        // 운영 배포 전 실제 검색엔진 클라이언트로 교체 필수 —
        // Mock INDEX는 개발 테스트용만 허용 (Roadmap Phase 5 · ADR 예정)
        $this->app->bind(SearchIndexClient::class, DevSearchIndexClient::class);

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
        //
    }
}
