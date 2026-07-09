<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Enums\StorageZone;
use App\Models\MediaRendition;
use App\Models\WorkflowJob;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Storage\RenditionService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\Tools\ToolRunner;
use ZipArchive;

/**
 * TEXT_EXTRACT — 문서 본문 텍스트 직접 추출 (Job Type Def §3.10 · Worker Agent Spec §9).
 * PDF는 pdftotext, DOCX·PPTX는 XML 파싱. 텍스트 0자여도 성공 처리하고
 * char_count < 50이면 result에 needs_ocr=true — Scheduler가 OCR 조건을 활성화한다.
 * 산출물은 EXTRACTED_TEXT rendition(파일 경로 저장 — media_texts 분리는 P3 검토).
 */
class TextExtractJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'TEXT_EXTRACT';

    /** 스캔 문서 간주 임계값 — Job Type Def §3.10 */
    private const int NEEDS_OCR_CHAR_THRESHOLD = 50;

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly RenditionService $renditions,
        private readonly ToolRunner $runner,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $master = MediaRendition::query()
            ->where('content_id', $job->content_id)
            ->where('rendition_type', 'MASTER')
            ->first();

        if ($master === null) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'MASTER rendition row missing');
        }

        $inputAbs = $this->storage->absolutePath($master->storage_zone, $master->path);

        if (! is_file($inputAbs)) {
            return JobResult::failure(FailureType::StorageError, 'STORAGE_IO', 'master file missing on storage');
        }

        $detectedMime = (string) ($ctx->mediaFile()?->detected_mime ?? '');

        $ctx->progress->report($job->id, 30.0, 'extracting text');

        if ($detectedMime === 'application/pdf') {
            $extracted = $this->extractFromPdf($job, $inputAbs);
        } else {
            $extracted = $this->extractFromOfficeXml($detectedMime, $inputAbs);
        }

        if ($extracted instanceof JobResult) {
            return $extracted;
        }

        $text = trim($extracted);
        $charCount = mb_strlen($text);

        $ctx->cancelToken->tick();
        $ctx->progress->report($job->id, 70.0, 'saving extracted text');

        $textTmp = $this->storage->tempFilePath(StorageZone::Document, $job->id, 'extracted.txt');
        file_put_contents($textTmp, $text);

        $targetRelative = sprintf(
            'document/%s/%s/%d/extracted.txt',
            now()->format('Y'), now()->format('m'), $job->content_id,
        );
        $finalAbs = $this->storage->promote(StorageZone::Document, $textTmp, $targetRelative);

        $this->renditions->upsert([
            'content_id' => $job->content_id,
            'media_file_id' => $master->media_file_id,
            'rendition_type' => 'EXTRACTED_TEXT',
            'storage_zone' => StorageZone::Document->value,
            'variant_key' => 'default',
            'path' => $targetRelative,
            'file_size' => $this->storage->fileSize($finalAbs),
            'checksum' => $this->storage->checksumSha256($finalAbs),
            'mime_type' => 'text/plain',
            'metadata' => ['char_count' => $charCount],
        ]);

        $ctx->progress->report($job->id, 100.0, 'text extracted');

        // 0자도 성공 — OCR 실행 조건만 활성화한다 (Job Type Def §3.10 성공 조건)
        return JobResult::success([
            'extracted_text_path' => $targetRelative,
            'char_count' => $charCount,
            'needs_ocr' => $charCount < self::NEEDS_OCR_CHAR_THRESHOLD,
        ]);
    }

    /** pdftotext — 파싱 오류는 재시도 가능한 도구 오류로 분류 */
    private function extractFromPdf(WorkflowJob $job, string $inputAbs): string|JobResult
    {
        $outputTmp = $this->storage->tempFilePath(StorageZone::Document, $job->id, 'pdftotext.txt');

        $result = $this->runner->run([
            (string) config('workflow.tools.pdftotext'),
            '-enc', 'UTF-8',
            $inputAbs, $outputTmp,
        ], timeoutSec: max(30, $job->timeout_sec - 30));

        if ($result->exitCode === 124) {
            return JobResult::failure(FailureType::ExternalToolError, 'TOOL_TIMEOUT', $result->stderrTail());
        }

        if (! $result->ok() || ! is_file($outputTmp)) {
            return JobResult::failure(FailureType::ExternalToolError, 'PDFTOTEXT_FAILED', $result->stderrTail());
        }

        return (string) file_get_contents($outputTmp);
    }

    /** DOCX·PPTX — ZipArchive로 XML 본문 파싱 (Worker Agent Spec §9) */
    private function extractFromOfficeXml(string $detectedMime, string $inputAbs): string|JobResult
    {
        $entryPatterns = match ($detectedMime) {
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['word/document.xml'],
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['ppt/slides/slide*.xml'],
            default => null,
        };

        if ($entryPatterns === null) {
            return JobResult::failure(
                FailureType::Permanent, 'UNSUPPORTED_FORMAT',
                "text extraction not supported for mime: {$detectedMime}", retryable: false,
            );
        }

        $zip = new ZipArchive;

        if ($zip->open($inputAbs) !== true) {
            return JobResult::failure(FailureType::Permanent, 'PERMANENT_CORRUPT', 'document container cannot be opened');
        }

        try {
            $chunks = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                foreach ($entryPatterns as $pattern) {
                    if (fnmatch($pattern, $name)) {
                        $xml = (string) $zip->getFromIndex($i);
                        // 블록 경계는 공백으로 — 단어가 붙지 않게 태그를 공백 치환 후 정규화
                        $chunks[$name] = trim(preg_replace('/\s+/u', ' ',
                            html_entity_decode(strip_tags(str_replace('<', ' <', $xml)))));
                    }
                }
            }

            ksort($chunks, SORT_NATURAL);

            return implode("\n", array_filter($chunks));
        } finally {
            $zip->close();
        }
    }
}
