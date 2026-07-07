<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Enums\IndexStatus;
use App\Models\SearchIndexState;
use App\Models\WorkflowJob;
use App\Services\Workflow\Search\SearchIndexClient;
use App\Services\Workflow\StateMachine\SearchIndexStateMachine;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * INDEX — 메타데이터 취합 → 색인 upsert → 조회 검증 → search_index_states 갱신 (Worker Agent Spec §9).
 * 엔진 오류는 SEARCH_ENGINE_ERROR(재시도, base 30초 백오프).
 */
class IndexJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'INDEX';

    public function __construct(
        private readonly SearchIndexClient $client,
        private readonly SearchIndexStateMachine $stateMachine,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $content = $ctx->content();
        $mediaFile = $ctx->mediaFile();
        $documentId = "content-{$content->id}";

        $document = [
            'content_id' => $content->id,
            'title' => $content->title,
            'description' => $content->description,
            'media_type' => $content->media_type?->value,
            'duration_ms' => $this->durationMs($mediaFile?->media_info),
            'indexed_from_instance' => $job->instance_id,
        ];

        $ctx->progress->report($job->id, 30.0, 'indexing document');

        try {
            $this->client->upsert($documentId, $document);

            // 색인 후 조회 검증 (Spec §9 INDEX ③)
            if ($this->client->get($documentId) === null) {
                return JobResult::failure(FailureType::SearchEngineError, 'INDEX_UNAVAILABLE', 'index verification read returned nothing');
            }
        } catch (Throwable $e) {
            return JobResult::failure(FailureType::SearchEngineError, 'INDEX_UNAVAILABLE', $e->getMessage());
        }

        $ctx->progress->report($job->id, 80.0, 'updating index state');

        $state = SearchIndexState::query()->firstOrCreate(
            ['content_id' => $content->id],
            ['status' => IndexStatus::Pending],
        )->refresh();

        // 재실행 멱등 — 이미 INDEXED면 문서만 갱신하고 전이는 생략 (INDEXED→INDEXED는 전이표에 없다)
        if ($state->status === IndexStatus::Indexed) {
            return JobResult::success([
                'index_doc_id' => $documentId,
                'index_version' => (int) $state->index_version,
                'already_indexed' => true,
            ]);
        }

        $newVersion = ((int) $state->index_version) + 1;

        DB::transaction(fn () => $this->stateMachine->transition(
            $state, IndexStatus::Indexed->value, "worker:{$ctx->worker->id}", null,
            [
                'indexed_at' => now(),
                'index_version' => $newVersion,
                'index_doc_id' => $documentId,
                'last_error' => null,
            ],
        ));

        $ctx->progress->report($job->id, 100.0, 'indexed');

        return JobResult::success(['index_doc_id' => $documentId, 'index_version' => $newVersion]);
    }

    private function durationMs(mixed $mediaInfo): ?int
    {
        if (is_array($mediaInfo) && isset($mediaInfo['format']['duration'])) {
            return (int) round(((float) $mediaInfo['format']['duration']) * 1000);
        }

        return null;
    }
}
