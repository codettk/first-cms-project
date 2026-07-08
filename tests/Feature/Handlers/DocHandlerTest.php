<?php

namespace Tests\Feature\Handlers;

use App\Enums\JobType;
use App\Enums\StorageZone;
use App\Models\MediaRendition;
use App\Services\Workflow\Search\SearchIndexClient;
use App\Services\Workflow\Worker\Handlers\CatalogJobHandler;
use App\Services\Workflow\Worker\Handlers\DocumentPreviewJobHandler;
use App\Services\Workflow\Worker\Handlers\IndexJobHandler;
use App\Services\Workflow\Worker\Handlers\MediaAnalyzeJobHandler;
use App\Services\Workflow\Worker\Handlers\TextExtractJobHandler;
use App\Services\Workflow\Worker\Handlers\VerifyJobHandler;
use App\Services\Workflow\Worker\Tools\ToolResult;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * M6 확장 — DOC 파이프라인: VERIFY(pdfinfo)·MA(DOC 판정)·DOC_PREVIEW(§3.8)·
 * TEXT_EXTRACT(§3.10)·CA 문서 분기(§3.7)·INDEX 텍스트 취합(§3.11).
 */
class DocHandlerTest extends HandlerTestCase
{
    private const string DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** DOC job + MASTER rendition 픽스처 (detected_mime은 VERIFY 산출을 시뮬레이션) */
    private function makeDocFixture(JobType $type, string $mime = 'application/pdf', array $jobAttributes = []): array
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob($type, $jobAttributes, ['detected_mime' => $mime]);

        $path = "master/2026/07/{$content->id}_src.bin";
        $this->putZoneFile(StorageZone::Master, $path, '%PDF-fake-master-bytes');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER', 'path' => $path,
        ]);

        return [$job, $content, $mediaFile];
    }

    private function pushPdfInfo(int $pages = 2, bool $encrypted = false): void
    {
        $flag = $encrypted ? 'yes' : 'no';
        $this->tools->push(fn () => new ToolResult(0, "Pages:          {$pages}\nEncrypted:      {$flag}\n", ''));
    }

    /** pdftoppm 페이크 — {prefix}-{n}.png 파일을 생성 (prefix는 명령 마지막 인자) */
    private function pushPdftoppmWritesPages(int $pages): void
    {
        $this->tools->push(function (array $command) use ($pages) {
            $prefix = end($command);
            for ($n = 1; $n <= $pages; $n++) {
                file_put_contents("{$prefix}-{$n}.png", "png-page-{$n}");
            }

            return new ToolResult(0, '', '');
        });
    }

    /** vipsthumbnail 페이크 — 출력 경로는 -o 인자([Q=..] 스펙 제거) */
    private function pushVipsWritesOutput(string $contents = 'webp-bytes'): void
    {
        $this->tools->push(function (array $command) use ($contents) {
            $out = preg_replace('/\[[^\]]*\]$/', '', $command[array_search('-o', $command, true) + 1]);
            file_put_contents($out, $contents);

            return new ToolResult(0, '', '');
        });
    }

    public function test_doc_preview_creates_page_preview_renditions_from_pdf(): void
    {
        [$job, $content] = $this->makeDocFixture(JobType::DocPreview, jobAttributes: [
            'payload' => ['profile' => 'DOC_PREVIEW_WEBP_144DPI'], 'timeout_sec' => 1800,
        ]);

        $this->pushPdfInfo(pages: 2);
        $this->pushPdftoppmWritesPages(2);
        $this->pushVipsWritesOutput('page-1-webp');
        $this->pushVipsWritesOutput('page-2-webp');

        $result = app(DocumentPreviewJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(2, $result->resultData['page_count']);
        $this->assertSame(2, $result->resultData['previews_created']);

        $previews = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'PAGE_PREVIEW')->orderBy('page_no')->get();
        $this->assertCount(2, $previews);
        $this->assertSame([1, 2], $previews->pluck('page_no')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('page_preview', $previews[0]->variant_key);
        $this->assertSame('DOCUMENT', $previews[0]->storage_zone->value);
        $this->assertFileExists($this->storage->absolutePath(StorageZone::Document, $previews[0]->path));

        // pdftoppm 표준 인자 — 144dpi·PNG·페이지 범위 (Transcode Profile Spec §13)
        $pdftoppm = $this->tools->commands[1];
        $this->assertContains('-r', $pdftoppm);
        $this->assertContains('144', $pdftoppm);
        $this->assertContains('-png', $pdftoppm);
    }

    public function test_doc_preview_converts_office_document_via_soffice_first(): void
    {
        [$job, $content] = $this->makeDocFixture(JobType::DocPreview, self::DOCX_MIME, [
            'payload' => ['profile' => 'DOC_PREVIEW_WEBP_144DPI'], 'timeout_sec' => 1800,
        ]);

        // soffice — {outdir}/{입력 파일명}.pdf 생성
        $this->tools->push(function (array $command) {
            $outDir = $command[array_search('--outdir', $command, true) + 1];
            $input = end($command);
            file_put_contents($outDir.DIRECTORY_SEPARATOR.pathinfo($input, PATHINFO_FILENAME).'.pdf', 'converted-pdf');

            return new ToolResult(0, '', '');
        });
        $this->pushPdfInfo(pages: 1);
        $this->pushPdftoppmWritesPages(1);
        $this->pushVipsWritesOutput();

        $result = app(DocumentPreviewJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(1, MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'PAGE_PREVIEW')->count());

        // headless 격리 실행 (Transcode Profile Spec §13)
        $soffice = $this->tools->commands[0];
        $this->assertContains('--headless', $soffice);
        $this->assertContains('--norestore', $soffice);
        $this->assertContains('--convert-to', $soffice);
    }

    public function test_doc_preview_rejects_encrypted_document_permanently(): void
    {
        [$job] = $this->makeDocFixture(JobType::DocPreview, jobAttributes: [
            'payload' => ['profile' => 'DOC_PREVIEW_WEBP_144DPI'],
        ]);

        $this->pushPdfInfo(pages: 5, encrypted: true);

        $result = app(DocumentPreviewJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('ENCRYPTED_DOC', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    public function test_doc_preview_respects_page_limit_with_warning(): void
    {
        [$job] = $this->makeDocFixture(JobType::DocPreview, jobAttributes: [
            'payload' => ['profile' => 'DOC_PREVIEW_WEBP_144DPI'],
        ]);

        $this->pushPdfInfo(pages: 300); // limit 200 초과
        $this->pushPdftoppmWritesPages(2); // 렌더 결과는 페이크로 2페이지만
        $this->pushVipsWritesOutput();
        $this->pushVipsWritesOutput();

        $result = app(DocumentPreviewJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(300, $result->resultData['page_count']);

        // pdftoppm은 상한 200까지만 요청 + WARN 기록 (Job Type Def §3.8)
        $pdftoppm = $this->tools->commands[1];
        $this->assertContains('200', $pdftoppm);
        $this->assertDatabaseHas('workflow_job_logs', ['job_id' => $job->id, 'level' => 'WARN']);
    }

    public function test_doc_preview_fails_when_no_page_converts(): void
    {
        [$job] = $this->makeDocFixture(JobType::DocPreview, jobAttributes: [
            'payload' => ['profile' => 'DOC_PREVIEW_WEBP_144DPI'],
        ]);

        $this->pushPdfInfo(pages: 1);
        $this->pushPdftoppmWritesPages(1);
        $this->tools->pushFailure(1, 'vips conversion failed');

        $result = app(DocumentPreviewJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('VIPS_FAILED', $result->failReasonCode);
    }

    public function test_text_extract_from_pdf_creates_extracted_text_rendition(): void
    {
        [$job, $content] = $this->makeDocFixture(JobType::TextExtract);

        $text = str_repeat('이 문서는 텍스트 레이어를 가진 PDF다. ', 5);
        $this->tools->push(function (array $command) use ($text) {
            file_put_contents(end($command), $text);

            return new ToolResult(0, '', '');
        });

        $result = app(TextExtractJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertGreaterThanOrEqual(50, $result->resultData['char_count']);
        $this->assertFalse($result->resultData['needs_ocr']);

        $rendition = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'EXTRACTED_TEXT')->first();
        $this->assertNotNull($rendition);
        $this->assertSame('DOCUMENT', $rendition->storage_zone->value);
        $this->assertSame($result->resultData['char_count'], $rendition->metadata['char_count']);
        $this->assertFileExists($this->storage->absolutePath(StorageZone::Document, $rendition->path));

        // UTF-8 강제 (Worker Agent Spec §9)
        $this->assertContains('UTF-8', $this->tools->commands[0]);
    }

    public function test_text_extract_flags_scanned_pdf_for_ocr(): void
    {
        [$job] = $this->makeDocFixture(JobType::TextExtract);

        // 스캔 문서 — 텍스트 레이어가 거의 없다 (char_count < 50)
        $this->tools->push(function (array $command) {
            file_put_contents(end($command), 'p1');

            return new ToolResult(0, '', '');
        });

        $result = app(TextExtractJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success);
        $this->assertTrue($result->resultData['needs_ocr']);
    }

    public function test_text_extract_parses_docx_xml_without_external_tool(): void
    {
        [$job, $content] = $this->makeDocFixture(JobType::TextExtract, self::DOCX_MIME);

        // MASTER를 실제 최소 DOCX(zip) 컨테이너로 교체
        $master = MediaRendition::where('content_id', $content->id)->firstOrFail();
        $abs = $this->storage->absolutePath(StorageZone::Master, $master->path);
        $zip = new ZipArchive;
        $zip->open($abs, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml',
            '<w:document><w:body><w:p><w:r><w:t>워크플로 문서 본문</w:t></w:r><w:r><w:t>두 번째 문장</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        $result = app(TextExtractJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame([], $this->tools->commands, '외부 도구 없이 XML 파싱해야 한다');

        $rendition = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'EXTRACTED_TEXT')->firstOrFail();
        $saved = file_get_contents($this->storage->absolutePath(StorageZone::Document, $rendition->path));
        $this->assertStringContainsString('워크플로 문서 본문', $saved);
        $this->assertStringContainsString('두 번째 문장', $saved);
    }

    public function test_text_extract_classifies_pdftotext_failure_as_retryable(): void
    {
        [$job] = $this->makeDocFixture(JobType::TextExtract);
        $this->tools->pushFailure(1, 'Syntax Error: Couldn\'t read xref table');

        $result = app(TextExtractJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('PDFTOTEXT_FAILED', $result->failReasonCode);
        $this->assertTrue($result->isRetryable());
    }

    public function test_ma_confirms_doc_type_from_detected_mime_without_probe(): void
    {
        [$job, $content] = $this->makeDocFixture(JobType::Ma);

        $result = app(MediaAnalyzeJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame('DOC', $result->resultData['media_type']);
        $this->assertSame('DOC', $content->fresh()->media_type->value);
        $this->assertSame([], $this->tools->commands, '문서는 ffprobe를 호출하지 않아야 한다');
    }

    public function test_verify_rejects_encrypted_pdf(): void
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Verify);

        $pdfBytes = "%PDF-1.4\nfake-encrypted-pdf";
        $path = "master/2026/07/{$content->id}_doc.pdf";
        $this->putZoneFile(StorageZone::Master, $path, $pdfBytes);
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER', 'path' => $path,
            'checksum' => hash('sha256', $pdfBytes),
        ]);

        $this->pushPdfInfo(pages: 3, encrypted: true);

        $result = app(VerifyJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertSame('ENCRYPTED_DOC', $result->failReasonCode);
        $this->assertFalse($result->isRetryable());
    }

    public function test_verify_accepts_intact_pdf(): void
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Verify);

        $pdfBytes = "%PDF-1.4\nfake-pdf-body";
        $path = "master/2026/07/{$content->id}_doc.pdf";
        $this->putZoneFile(StorageZone::Master, $path, $pdfBytes);
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'MASTER', 'storage_zone' => 'MASTER', 'path' => $path,
            'checksum' => hash('sha256', $pdfBytes),
        ]);

        $this->pushPdfInfo(pages: 3);

        $result = app(VerifyJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame('application/pdf', $result->resultData['detected_mime']);
    }

    public function test_ca_for_doc_creates_thumbnail_from_first_page_preview(): void
    {
        [$job, $content, $mediaFile] = $this->makeRunningJob(JobType::Ca);
        DB::table('contents')->where('id', $content->id)->update(['media_type' => 'DOC']);

        $path = "document/2026/07/{$content->id}/page_1.webp";
        $this->putZoneFile(StorageZone::Document, $path, 'page-1-webp');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'PAGE_PREVIEW', 'storage_zone' => 'DOCUMENT',
            'variant_key' => 'page_preview', 'path' => $path, 'page_no' => 1,
        ]);

        $this->pushVipsWritesOutput('doc-thumb');

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');
        $this->assertSame(0, $result->resultData['catalog_created']);
        $this->assertSame(1, MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'THUMBNAIL')->count());
    }

    public function test_ca_for_doc_requires_first_page_preview(): void
    {
        [$job, $content] = $this->makeRunningJob(JobType::Ca);
        DB::table('contents')->where('id', $content->id)->update(['media_type' => 'DOC']);

        $result = app(CatalogJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertFalse($result->success);
        $this->assertSame('STORAGE_IO', $result->failReasonCode);
    }

    public function test_index_includes_extracted_text_in_document(): void
    {
        [$job, $content, $mediaFile] = $this->makeDocFixture(JobType::Index);
        DB::table('contents')->where('id', $content->id)->update(['media_type' => 'DOC']);

        $textPath = "document/2026/07/{$content->id}/extracted.txt";
        $this->putZoneFile(StorageZone::Document, $textPath, '검색 색인에 포함될 본문 텍스트');
        MediaRendition::create([
            'content_id' => $content->id, 'media_file_id' => $mediaFile->id,
            'rendition_type' => 'EXTRACTED_TEXT', 'storage_zone' => 'DOCUMENT',
            'variant_key' => 'default', 'path' => $textPath,
        ]);

        $result = app(IndexJobHandler::class)->handle($job, $this->makeContext($job));

        $this->assertTrue($result->success, $result->failMessage ?? '');

        $document = app(SearchIndexClient::class)->get("content-{$content->id}");
        $this->assertSame('검색 색인에 포함될 본문 텍스트', $document['extracted_text']);
        $this->assertSame('DOC', $document['media_type']);
    }
}
