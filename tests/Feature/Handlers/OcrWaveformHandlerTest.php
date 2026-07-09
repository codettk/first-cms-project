<?php

namespace Tests\Feature\Handlers;

use App\Enums\JobType;
use App\Enums\StorageZone;
use App\Models\Content;
use App\Models\MediaFile;
use App\Models\MediaRendition;
use App\Models\SearchIndexState;
use App\Models\WorkflowJob;
use App\Services\Workflow\Worker\Handlers\OcrJobHandler;
use App\Services\Workflow\Worker\Handlers\WaveformJobHandler;
use App\Services\Workflow\Worker\Tools\ToolResult;

/**
 * 선택 작업 Handler — WAVEFORM(Job Type Def §3.6 · Transcode Profile Spec §6) ·
 * OCR(Job Type Def §3.9 · SM Spec §7·§14 STALE 예약).
 */
class OcrWaveformHandlerTest extends HandlerTestCase
{
    /** @return array{0: WorkflowJob, 1: Content, 2: MediaFile} */
    private function makeMasterFixture(JobType $type, string $mediaType, string $ext): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob($type);
        $content->update(['media_type' => $mediaType]);

        $path = "master/2026/07/{$content->id}_src.{$ext}";
        $this->putZoneFile(StorageZone::Master, $path, 'fake-master-bytes');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER', 'path' => $path,
        ]);

        return [$job, $content->fresh(), $mediaFile];
    }

    /** audiowaveform 페이크 — '-o' 인자 경로에 JSON 기록 */
    private function pushWaveformJson(array $json): void
    {
        $this->tools->push(function (array $command) use ($json) {
            $i = array_search('-o', $command, true);
            file_put_contents($command[$i + 1], json_encode($json));

            return new ToolResult(0, '', '');
        });
    }

    /** tesseract 페이크 — outputBase(3번째 인자).tsv에 word 레벨 tsv 기록 */
    private function pushTesseractTsv(string ...$words): void
    {
        $this->tools->push(function (array $command) use ($words) {
            $rows = ["level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext"];
            foreach ($words as $n => $word) {
                $rows[] = "5\t1\t1\t1\t1\t".($n + 1)."\t0\t0\t10\t10\t91.5\t{$word}";
            }
            file_put_contents($command[2].'.tsv', implode("\n", $rows)."\n");

            return new ToolResult(0, '', '');
        });
    }

    public function test_waveform_generates_peaks_json_rendition(): void
    {
        [$job, $content] = $this->makeMasterFixture(JobType::Waveform, 'AUDIO', 'wav');
        $this->pushWaveformJson(['version' => 2, 'sample_rate' => 44100, 'data' => [0, 5, -3, 8]]);

        $result = app(WaveformJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(4, $result->resultData['peaks']);
        $this->assertSame('AUDIO_WAVEFORM_JSON', $result->resultData['profile']);

        $rendition = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'WAVEFORM')->first();
        $this->assertNotNull($rendition);
        $this->assertSame('wave_json', $rendition->variant_key);
        $this->assertSame('PROXY', $rendition->storage_zone->value);
        $this->assertSame(4, $rendition->metadata['peaks']);
        $this->assertNotNull($rendition->profile_id);
        $this->assertFileExists($this->storage->absolutePath(StorageZone::Proxy, $rendition->path));

        // seed 프로파일 params 반영 (pixels_per_second 20 · bits 8)
        $command = $this->tools->commands[0];
        $this->assertContains('--pixels-per-second', $command);
        $this->assertContains('20', $command);
        $this->assertContains('-b', $command);
        $this->assertContains('8', $command);
    }

    public function test_waveform_rejects_output_without_peaks(): void
    {
        [$job] = $this->makeMasterFixture(JobType::Waveform, 'AUDIO', 'wav');
        $this->tools->push(function (array $command) {
            $i = array_search('-o', $command, true);
            file_put_contents($command[$i + 1], 'not-a-json');

            return new ToolResult(0, '', '');
        });

        $result = app(WaveformJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('AUDIOWAVEFORM_FAILED', $result->failReasonCode);
    }

    public function test_waveform_tool_failure_is_retryable(): void
    {
        [$job] = $this->makeMasterFixture(JobType::Waveform, 'AUDIO', 'wav');
        $this->tools->pushFailure(1, 'unsupported container');

        $result = app(WaveformJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('AUDIOWAVEFORM_FAILED', $result->failReasonCode);
        $this->assertTrue($result->isRetryable());
    }

    /** @return array{0: WorkflowJob, 1: Content, 2: MediaFile} */
    private function makeDocOcrFixture(int $pages = 2): array
    {
        [$job, $content, $mediaFile] = $this->makeMasterFixture(JobType::Ocr, 'DOC', 'pdf');

        for ($n = 1; $n <= $pages; $n++) {
            $path = "document/2026/07/{$content->id}/page_{$n}.webp";
            $this->putZoneFile(StorageZone::Document, $path, "fake-webp-page-{$n}");
            MediaRendition::create([
                'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
                'rendition_type' => 'PAGE_PREVIEW', 'storage_zone' => 'DOCUMENT',
                'variant_key' => 'page_preview', 'path' => $path, 'page_no' => $n,
            ]);
        }

        return [$job, $content, $mediaFile];
    }

    public function test_ocr_document_pages_produce_ocr_text_and_mark_index_stale(): void
    {
        [$job, $content] = $this->makeDocOcrFixture(pages: 2);
        SearchIndexState::create([
            'content_id' => $content->id, 'status' => 'INDEXED',
            'index_version' => 1, 'index_doc_id' => "content-{$content->id}",
        ]);
        $this->pushTesseractTsv('Hello', 'World');
        $this->pushTesseractTsv('Second', 'Page');

        $result = app(OcrJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(2, $result->resultData['pages_processed']);
        $this->assertSame(0, $result->resultData['pages_failed']);
        $this->assertSame('kor+eng', $result->resultData['ocr_lang']);
        $this->assertEqualsWithDelta(91.5, $result->resultData['ocr_confidence'], 0.01);

        $rendition = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'OCR_TEXT')->first();
        $this->assertNotNull($rendition);
        $this->assertSame('ocr_default', $rendition->variant_key);
        $this->assertSame('DOCUMENT', $rendition->storage_zone->value);
        $this->assertCount(2, $rendition->metadata['pages']);

        $text = file_get_contents($this->storage->absolutePath(StorageZone::Document, $rendition->path));
        $this->assertStringContainsString('Hello World', $text);
        $this->assertStringContainsString('Second Page', $text);

        // tesseract 표준 인자 — DOC_OCR_KO_EN params (lang kor+eng · psm 3 · tsv 출력)
        $command = $this->tools->commands[0];
        $this->assertContains('-l', $command);
        $this->assertContains('kor+eng', $command);
        $this->assertContains('--psm', $command);
        $this->assertContains('tsv', $command);

        // 선택 작업 SUCCESS → INDEXED→STALE 재색인 예약 (SM Spec §7·§14)
        $this->assertSame('STALE', SearchIndexState::find($content->id)->status->value);
    }

    public function test_ocr_image_reads_proxy_image_source(): void
    {
        [$job, $content, $mediaFile] = $this->makeMasterFixture(JobType::Ocr, 'IMAGE', 'jpg');
        $proxyPath = "proxy/2026/07/{$content->id}_IMAGE_PROXY_WEBP_2048.webp";
        $this->putZoneFile(StorageZone::Proxy, $proxyPath, 'fake-proxy-webp');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'PROXY_IMAGE', 'storage_zone' => 'PROXY', 'path' => $proxyPath,
        ]);
        $this->pushTesseractTsv('Signboard');

        $result = app(OcrJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(1, $result->resultData['pages_processed']);
        $this->assertStringContainsString('proxy', str_replace('\\', '/', $this->tools->commands[0][1]));
    }

    public function test_ocr_fails_when_all_pages_fail(): void
    {
        [$job] = $this->makeDocOcrFixture(pages: 2);
        $this->tools->pushFailure(1, 'cannot read image');
        $this->tools->pushFailure(1, 'cannot read image');

        $result = app(OcrJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('TESSERACT_FAILED', $result->failReasonCode);
    }

    public function test_ocr_leaves_non_indexed_state_untouched(): void
    {
        [$job, $content] = $this->makeDocOcrFixture(pages: 1);
        SearchIndexState::create(['content_id' => $content->id, 'status' => 'PENDING']);
        $this->pushTesseractTsv('Only');

        $result = app(OcrJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame('PENDING', SearchIndexState::find($content->id)->status->value);
    }

    public function test_ocr_without_source_renditions_is_storage_error(): void
    {
        [$job] = $this->makeMasterFixture(JobType::Ocr, 'DOC', 'pdf');

        $result = app(OcrJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('STORAGE_IO', $result->failReasonCode);
    }
}
