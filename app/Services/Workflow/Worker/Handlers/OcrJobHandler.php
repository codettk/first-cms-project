<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Enums\IndexStatus;
use App\Enums\StorageZone;
use App\Exceptions\ProfileInactiveException;
use App\Models\MediaRendition;
use App\Models\SearchIndexState;
use App\Models\WorkflowJob;
use App\Services\Workflow\StateMachine\SearchIndexStateMachine;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Storage\ProfileCompiler;
use App\Services\Workflow\Storage\RenditionService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\Tools\ToolRunner;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * OCR — 이미지·스캔 문서에서 검색 텍스트 추출 (Job Type Def §3.9 · Worker Agent Spec §3).
 * DOC은 PAGE_PREVIEW 페이지별, IMAGE는 PROXY_IMAGE를 tesseract(tsv)로 처리해
 * OCR_TEXT rendition(ocr_text·ocr_confidence·ocr_lang·페이지 매핑)을 만든다.
 * 선택 작업 — 실패해도 PUBLISH 비차단. SUCCESS 시 search_index_states INDEXED→STALE
 * 전이로 재색인을 예약한다 (SM Spec §7·§14).
 */
class OcrJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'OCR';

    /** 템플릿 step config에 profile이 없을 때의 기본 — seed 12종 계약 내 코드 */
    private const string DEFAULT_PROFILE = 'DOC_OCR_KO_EN';

    private const int DEFAULT_PAGE_LIMIT = 200;

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly RenditionService $renditions,
        private readonly ProfileCompiler $compiler,
        private readonly ToolRunner $runner,
        private readonly SearchIndexStateMachine $indexStates,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $profileCode = $ctx->payload()['profile'] ?? self::DEFAULT_PROFILE;

        try {
            $profile = $this->compiler->loadByCode((string) $profileCode);
        } catch (ProfileInactiveException $e) {
            return JobResult::failure(FailureType::SystemError, 'PROFILE_INACTIVE', $e->getMessage(), retryable: false);
        } catch (DomainException $e) {
            return JobResult::failure(FailureType::SystemError, 'PROFILE_MISSING', $e->getMessage(), retryable: false);
        }

        $sources = $this->sourceRenditions($job, $ctx);

        if ($sources instanceof JobResult) {
            return $sources;
        }

        $params = $profile->params ?? [];
        $pageLimit = (int) ($params['page_limit'] ?? self::DEFAULT_PAGE_LIMIT);
        $lang = (string) ($params['lang'] ?? '');

        if (count($sources) > $pageLimit) {
            $ctx->logger->warn($job, 'ocr page limit exceeded — processing first pages only', [
                'total_pages' => count($sources), 'page_limit' => $pageLimit,
            ]);
            $sources = array_slice($sources, 0, $pageLimit);
        }

        $pageTexts = [];
        $pageMap = [];
        $confidences = [];
        $pagesFailed = 0;
        $total = count($sources);

        foreach (array_values($sources) as $i => $rendition) {
            $ctx->cancelToken->tick();
            $pageNo = $rendition->page_no ?? ($i + 1);
            $inputAbs = $this->storage->absolutePath($rendition->storage_zone, $rendition->path);

            if (! is_file($inputAbs)) {
                $pagesFailed++;

                continue;
            }

            $outBase = $this->storage->tempFilePath(StorageZone::Document, $job->id, "ocr_page_{$pageNo}");

            $result = $this->runner->run([
                (string) config('workflow.tools.tesseract'),
                ...$this->compiler->tesseractArgs($profile, $inputAbs, $outBase),
            ], timeoutSec: max(30, $job->timeout_sec - 30));

            if ($result->exitCode === 124) {
                return JobResult::failure(FailureType::ExternalToolError, 'TOOL_TIMEOUT', $result->stderrTail());
            }

            if (! $result->ok() || ! is_file("{$outBase}.tsv")) {
                $ctx->logger->warn($job, "ocr failed for page {$pageNo}", [], $result->stderrTail());
                $pagesFailed++;

                continue;
            }

            [$text, $confs] = $this->parseTsv((string) file_get_contents("{$outBase}.tsv"));

            $pageTexts[] = $text;
            $pageMap[] = ['page_no' => $pageNo, 'char_count' => mb_strlen($text)];
            $confidences = [...$confidences, ...$confs];

            $ctx->progress->report($job->id, min(90.0, (($i + 1) / max(1, $total)) * 90.0), "ocr page {$pageNo}/{$total}");
        }

        if ($pageMap === []) {
            return JobResult::failure(FailureType::ExternalToolError, 'TESSERACT_FAILED', 'all OCR pages failed');
        }

        $fullText = trim(implode("\n\n", array_filter($pageTexts, fn ($t) => $t !== '')));
        $charCount = mb_strlen($fullText);
        $confidence = $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 1);

        $textTmp = $this->storage->tempFilePath(StorageZone::Document, $job->id, 'ocr.txt');
        file_put_contents($textTmp, $fullText);

        $targetRelative = sprintf(
            'document/%s/%s/%d/ocr.txt',
            now()->format('Y'), now()->format('m'), $job->content_id,
        );
        $finalAbs = $this->storage->promote(StorageZone::Document, $textTmp, $targetRelative);

        $this->renditions->upsert([
            'content_id' => $job->content_id,
            'media_file_id' => $sources[0]->media_file_id,
            'rendition_type' => $profile->rendition_type->value,
            'storage_zone' => StorageZone::Document->value,
            'variant_key' => $profile->variant_key,
            'path' => $targetRelative,
            'file_size' => $this->storage->fileSize($finalAbs),
            'checksum' => $this->storage->checksumSha256($finalAbs),
            'mime_type' => 'text/plain',
            'metadata' => [
                'char_count' => $charCount,
                'ocr_confidence' => $confidence,
                'ocr_lang' => $lang,
                'pages' => $pageMap,
            ],
            'profile_id' => $profile->id,
        ]);

        // 색인 반영 예약 — 선택 작업 SUCCESS 시 INDEXED→STALE (SM Spec §7·§14)
        $this->markIndexStale($job, $ctx);

        $ctx->progress->report($job->id, 100.0, 'ocr completed');

        return JobResult::success([
            'ocr_text_path' => $targetRelative,
            'char_count' => $charCount,
            'ocr_confidence' => $confidence,
            'ocr_lang' => $lang,
            'pages_processed' => count($pageMap),
            'pages_failed' => $pagesFailed,
        ]);
    }

    /**
     * OCR 입력 rendition — DOC=PAGE_PREVIEW 페이지들, IMAGE=PROXY_IMAGE (Worker Agent Spec §3: PROXY/DOCUMENT R).
     *
     * @return list<MediaRendition>|JobResult
     */
    private function sourceRenditions(WorkflowJob $job, JobExecutionContext $ctx): array|JobResult
    {
        $mediaType = $ctx->content()->media_type?->value;

        $sources = match ($mediaType) {
            'DOC' => MediaRendition::query()
                ->where('content_id', $job->content_id)
                ->where('rendition_type', 'PAGE_PREVIEW')
                ->orderBy('page_no')
                ->get()->all(),
            'IMAGE' => MediaRendition::query()
                ->where('content_id', $job->content_id)
                ->where('rendition_type', 'PROXY_IMAGE')
                ->limit(1)
                ->get()->all(),
            default => null,
        };

        if ($sources === null) {
            return JobResult::failure(
                FailureType::Permanent, 'UNSUPPORTED_FORMAT',
                "OCR not supported for media_type: {$mediaType}", retryable: false,
            );
        }

        if ($sources === []) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'no OCR source renditions found');
        }

        return $sources;
    }

    /**
     * tesseract tsv 파싱 — word 레벨(level=5)만 취해 line 단위로 재조립하고 conf 평균을 만든다.
     *
     * @return array{0: string, 1: list<float>}
     */
    private function parseTsv(string $tsv): array
    {
        $lines = [];
        $confidences = [];
        $currentKey = null;
        $currentWords = [];

        foreach (preg_split('/\r?\n/', $tsv) as $i => $row) {
            if ($i === 0 || trim($row) === '') {
                continue;
            }

            $cols = explode("\t", $row);

            if (count($cols) < 12 || (int) $cols[0] !== 5) {
                continue;
            }

            $word = trim($cols[11]);

            if ($word === '') {
                continue;
            }

            $conf = (float) $cols[10];

            if ($conf >= 0) {
                $confidences[] = $conf;
            }

            $lineKey = "{$cols[1]}-{$cols[2]}-{$cols[3]}-{$cols[4]}";

            if ($lineKey !== $currentKey && $currentWords !== []) {
                $lines[] = implode(' ', $currentWords);
                $currentWords = [];
            }

            $currentKey = $lineKey;
            $currentWords[] = $word;
        }

        if ($currentWords !== []) {
            $lines[] = implode(' ', $currentWords);
        }

        return [implode("\n", $lines), $confidences];
    }

    /** INDEXED일 때만 STALE 예약 — PENDING/STALE/FAILED는 이미 dirty 재색인 대상이다 */
    private function markIndexStale(WorkflowJob $job, JobExecutionContext $ctx): void
    {
        $state = SearchIndexState::query()->find($job->content_id);

        if ($state === null || $state->status !== IndexStatus::Indexed) {
            return;
        }

        DB::transaction(fn () => $this->indexStates->transition(
            $state, IndexStatus::Stale->value, "worker:{$ctx->worker->id}", 'ocr_completed',
        ));
    }
}
