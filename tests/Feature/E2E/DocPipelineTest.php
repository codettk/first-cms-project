<?php

namespace Tests\Feature\E2E;

use App\Enums\ContentStatus;
use App\Enums\StorageZone;
use App\Models\Content;
use App\Models\MediaFile;
use App\Models\MediaRendition;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\Search\SearchIndexClient;
use App\Services\Workflow\StateMachine\ContentStateMachine;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Worker\WorkerRunner;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * M6 DOC 파이프라인 E2E — PDF 업로드 → TM → VERIFY(pdfinfo) → MA(DOC 판정) →
 * DOC_PREVIEW(pdftoppm+vips) · TEXT_EXTRACT(pdftotext) → CA(1페이지 썸네일) →
 * INDEX(추출 텍스트 포함) → PUBLISH → CLEANUP → READY (Job Type Def §4 DOC 체인).
 * tesseract(+kor) 가용 시 선택 OCR → INDEXED→STALE → 재색인까지 검증한다.
 *
 * 실제 poppler/vipsthumbnail을 사용한다 — 미설치 환경에서는 skip.
 * 도구 격리 검증은 DocHandlerTest·OcrWaveformHandlerTest(FakeToolRunner)가 커버한다.
 */
class DocPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    private MediaStorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['pdfinfo', '-v'], ['pdftoppm', '-v'], ['pdftotext', '-v'],
            ['vipsthumbnail', '--vips-version'],
        ] as [$tool, $flag]) {
            $probe = new Process([config("workflow.tools.{$tool}", $tool), $flag]);
            $probe->run();
            // poppler 계열은 -v 출력을 stderr로 낸다 — 버전 문자열 존재도 가용으로 본다
            if (! $probe->isSuccessful()
                && ! str_contains(strtolower($probe->getErrorOutput().$probe->getOutput()), 'version')) {
                $this->markTestSkipped("{$tool} not available");
            }
        }

        $this->seed();

        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wf-e2e-doc-'.getmypid().'-'.uniqid();
        config(['workflow.storage.root' => $this->storageRoot]);
        config(['workflow.storage.zones' => array_fill_keys(
            ['TEMP', 'MASTER', 'PROXY', 'THUMBNAIL', 'CATALOG', 'DOCUMENT', 'ARCHIVE'], null
        )]);

        $this->storage = app(MediaStorageService::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->storageRoot) && is_dir($this->storageRoot)) {
            exec(PHP_OS_FAMILY === 'Windows'
                ? 'rmdir /s /q "'.$this->storageRoot.'"'
                : 'rm -rf "'.$this->storageRoot.'"');
        }

        parent::tearDown();
    }

    /** 오프셋을 계산한 유효한 1페이지 텍스트 PDF 생성 */
    private function buildPdf(string $text): string
    {
        $stream = "BT /F1 14 Tf 50 750 Td ({$text}) Tj ET";
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] '
                .'/Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
    }

    public function test_uploaded_pdf_reaches_ready_with_text_indexed(): void
    {
        $sampleText = 'Frame CMS document pipeline end to end verification sample text for search indexing.';

        $tempDir = $this->storage->zonePath(StorageZone::Temp).DIRECTORY_SEPARATOR.'temp';
        @mkdir($tempDir, 0775, true);
        $uploadAbs = $tempDir.DIRECTORY_SEPARATOR.'e2e-upload.pdf';
        file_put_contents($uploadAbs, $this->buildPdf($sampleText));

        $content = Content::factory()->registered()->create([
            'title' => 'E2E 문서', 'created_by' => User::factory(),
        ]);
        MediaFile::factory()->for($content)->create([
            'original_filename' => 'e2e-upload.pdf', 'ext' => 'pdf',
            'temp_path' => 'temp/e2e-upload.pdf',
            'file_size' => filesize($uploadAbs),
            'checksum' => hash_file('sha256', $uploadAbs),
        ]);

        $template = WorkflowTemplate::where('code', 'DOCUMENT_INGEST')->firstOrFail();

        DB::transaction(function () use ($content, $template) {
            app(WorkflowInstanceFactory::class)->createForContent($content, $template);
            app(ContentStateMachine::class)->transition($content, 'PROCESSING', 'cms-web', 'ingest');
        });

        $agent = WorkflowWorkerAgent::factory()->online()->create([
            'worker_name' => 'e2e-doc-all-in-one',
            'supported_job_types' => ['TM', 'VERIFY', 'MA', 'DOC_PREVIEW', 'TEXT_EXTRACT', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'],
        ]);

        $scheduler = app(WorkflowSchedulerService::class);
        $runner = app(WorkerRunner::class);

        $pipelineDone = fn (): bool => $content->fresh()->status === ContentStatus::Ready
            && DB::table('workflow_jobs')->where('content_id', $content->id)
                ->where('job_type', 'CLEANUP')->value('status') === 'SUCCESS';

        for ($i = 0; $i < 27 && ! $pipelineDone(); $i++) {
            $scheduler->tick();
            $runner->run($agent, once: true);
        }

        $freshContent = $content->fresh();
        $this->assertSame('READY', $freshContent->status->value, 'content가 READY에 도달하지 못했다: '.
            json_encode(DB::table('workflow_jobs')->where('content_id', $content->id)
                ->pluck('status', 'job_type')));
        $this->assertSame('DOC', $freshContent->media_type->value);

        $statuses = DB::table('workflow_jobs')->where('content_id', $content->id)
            ->pluck('status', 'job_type');
        foreach (['TM', 'VERIFY', 'MA', 'DOC_PREVIEW', 'TEXT_EXTRACT', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'] as $type) {
            $this->assertSame('SUCCESS', $statuses[$type], "{$type}가 SUCCESS가 아니다");
        }

        // 필수 rendition — DOC은 MASTER + PAGE_PREVIEW (SM Spec §8) + CA 썸네일
        foreach (['MASTER', 'PAGE_PREVIEW', 'THUMBNAIL', 'EXTRACTED_TEXT'] as $type) {
            $rendition = MediaRendition::where('content_id', $content->id)
                ->where('rendition_type', $type)->first();
            $this->assertNotNull($rendition, "{$type} rendition row 누락");
            $this->assertFileExists(
                $this->storage->absolutePath($rendition->storage_zone, $rendition->path),
                "{$type} 파일 누락",
            );
        }

        // 본문 텍스트 추출 — 스캔 문서가 아니므로 needs_ocr=false (Job Type Def §3.10)
        $extracted = MediaRendition::where('content_id', $content->id)
            ->where('rendition_type', 'EXTRACTED_TEXT')->firstOrFail();
        $this->assertGreaterThanOrEqual(50, (int) $extracted->metadata['char_count']);

        // 색인 문서에 추출 텍스트 포함 (Job Type Def §3.11)
        $this->assertSame('INDEXED', DB::table('search_index_states')
            ->where('content_id', $content->id)->value('status'));
        $indexed = app(SearchIndexClient::class)->get("content-{$content->id}");
        $this->assertNotNull($indexed);
        $this->assertStringContainsString('pipeline', (string) ($indexed['extracted_text'] ?? ''));

        $this->runOptionalOcrPhase($content->id);
    }

    /** tesseract(+kor) 가용 시 — 선택 OCR SUCCESS → STALE → 재색인 INDEXED 회복까지 */
    private function runOptionalOcrPhase(int $contentId): void
    {
        $langs = new Process([(string) config('workflow.tools.tesseract'), '--list-langs']);
        $langs->run();
        if (! $langs->isSuccessful()
            || ! str_contains($langs->getOutput().$langs->getErrorOutput(), 'kor')) {
            return; // OCR 단계는 선택 검증 — 도구/언어팩 없으면 본 검증까지로 종료
        }

        $scheduler = app(WorkflowSchedulerService::class);
        $runner = app(WorkerRunner::class);
        $agent = WorkflowWorkerAgent::factory()->online()->create([
            'worker_name' => 'e2e-doc-ocr-index',
            'supported_job_types' => ['OCR', 'INDEX'],
        ]);

        $ocrDone = fn (): bool => DB::table('search_index_states')
                ->where('content_id', $contentId)->value('status') === 'INDEXED'
            && DB::table('workflow_jobs')->where('content_id', $contentId)
                ->where('job_type', 'OCR')->value('status') === 'SUCCESS';

        for ($i = 0; $i < 12 && ! $ocrDone(); $i++) {
            $scheduler->tick();
            $runner->run($agent, once: true);
        }

        $this->assertSame('SUCCESS', DB::table('workflow_jobs')
            ->where('content_id', $contentId)->where('job_type', 'OCR')->value('status'), 'OCR가 SUCCESS가 아니다');
        $this->assertNotNull(MediaRendition::where('content_id', $contentId)
            ->where('rendition_type', 'OCR_TEXT')->first(), 'OCR_TEXT rendition 누락');

        // 선택 작업 SUCCESS → STALE → Scheduler 재색인 → INDEXED 회복 (SM Spec §14)
        $state = DB::table('search_index_states')->where('content_id', $contentId)->first();
        $this->assertSame('INDEXED', $state->status);
        $this->assertSame(2, (int) $state->index_version);
    }
}
