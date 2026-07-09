<?php

namespace App\Services\Workflow\Worker\Handlers;

use App\Enums\FailureType;
use App\Enums\StorageZone;
use App\Exceptions\ProfileInactiveException;
use App\Models\MediaRendition;
use App\Models\WorkflowJob;
use App\Services\Workflow\Storage\MediaStorageService;
use App\Services\Workflow\Storage\ProfileCompiler;
use App\Services\Workflow\Storage\RenditionService;
use App\Services\Workflow\Worker\JobExecutionContext;
use App\Services\Workflow\Worker\JobResult;
use App\Services\Workflow\Worker\Tools\ToolRunner;
use DomainException;

/**
 * DOC_PREVIEW — 문서 페이지별 미리보기 생성 (Job Type Def §3.8 · Transcode Profile Spec §13).
 * PDF는 pdftoppm 직접, Office 문서는 soffice headless→PDF 선변환. 암호화 문서는
 * pdfinfo 사전 검사에서 ENCRYPTED_DOC(PERMANENT). page limit 초과분은 WARN 후 상한까지만.
 * 개별 페이지 렌더 실패는 건너뛰고 기록 — 1페이지 이상 성공이면 SUCCESS.
 */
class DocumentPreviewJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'DOC_PREVIEW';

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly RenditionService $renditions,
        private readonly ProfileCompiler $compiler,
        private readonly ToolRunner $runner,
    ) {}

    public function handle(WorkflowJob $job, JobExecutionContext $ctx): JobResult
    {
        $profileCode = $ctx->payload()['profile'] ?? null;

        if (! is_string($profileCode) || $profileCode === '') {
            return JobResult::failure(FailureType::SystemError, 'PROFILE_MISSING', 'payload.profile is required', retryable: false);
        }

        try {
            $profile = $this->compiler->loadByCode($profileCode);
        } catch (ProfileInactiveException $e) {
            return JobResult::failure(FailureType::SystemError, 'PROFILE_INACTIVE', $e->getMessage(), retryable: false);
        } catch (DomainException $e) {
            return JobResult::failure(FailureType::SystemError, 'PROFILE_MISSING', $e->getMessage(), retryable: false);
        }

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

        $workDir = $this->storage->tempWorkDir(StorageZone::Document, $job->id);
        $detectedMime = (string) ($ctx->mediaFile()?->detected_mime ?? '');

        // ── Office 문서는 soffice headless로 PDF 선변환 (Spec §13 — job 전용 프로필 격리)
        if ($detectedMime !== 'application/pdf') {
            $ctx->progress->report($job->id, 10.0, 'converting to pdf');

            $convert = $this->runner->run([
                (string) config('workflow.tools.soffice'),
                '--headless', '--norestore',
                '-env:UserInstallation=file:///'.str_replace('\\', '/', $workDir).'/lo-profile',
                '--convert-to', 'pdf', '--outdir', $workDir,
                $inputAbs,
            ], timeoutSec: max(30, $job->timeout_sec - 30));

            $pdfAbs = $workDir.DIRECTORY_SEPARATOR.pathinfo($inputAbs, PATHINFO_FILENAME).'.pdf';

            if (! $convert->ok() || ! is_file($pdfAbs)) {
                return JobResult::failure(FailureType::ExternalToolError, 'SOFFICE_CRASH', $convert->stderrTail());
            }
        } else {
            $pdfAbs = $inputAbs;
        }

        // ── pdfinfo 사전 검사 — 암호화 감지·총 페이지 수 확정 (Spec §13)
        $ctx->cancelToken->tick();
        $info = $this->runner->run([(string) config('workflow.tools.pdfinfo'), $pdfAbs], timeoutSec: 60);

        if (! $info->ok()) {
            return JobResult::failure(FailureType::Permanent, 'PERMANENT_CORRUPT', 'pdfinfo parse failed: '.$info->stderrTail());
        }

        if (preg_match('/^Encrypted:\s*yes/mi', $info->stdout)) {
            return JobResult::failure(FailureType::Permanent, 'ENCRYPTED_DOC', 'encrypted document — preview is not possible');
        }

        if (! preg_match('/^Pages:\s*(\d+)/mi', $info->stdout, $m)) {
            return JobResult::failure(FailureType::Permanent, 'PERMANENT_CORRUPT', 'pdfinfo did not report page count');
        }

        $pageCount = (int) $m[1];
        $params = $profile->params ?? [];
        $pageLimit = (int) ($params['page_limit'] ?? 200);
        $renderPages = min($pageCount, $pageLimit);

        if ($pageCount > $pageLimit) {
            // 초과분은 생성하지 않고 WARN — SUCCESS 유지 (Job Type Def §3.8)
            $ctx->logger->warn($job, "page limit reached — rendering {$renderPages}/{$pageCount} pages");
        }

        // ── pdftoppm — 상한까지 PNG 렌더 (Spec §13)
        $ctx->progress->report($job->id, 30.0, 'rendering pages');

        $prefix = $workDir.DIRECTORY_SEPARATOR.'page';
        $render = $this->runner->run([
            (string) config('workflow.tools.pdftoppm'),
            '-r', (string) ($params['dpi'] ?? 144),
            '-png', '-f', '1', '-l', (string) $renderPages,
            $pdfAbs, $prefix,
        ], timeoutSec: max(30, $job->timeout_sec - 30));

        if (! $render->ok()) {
            return JobResult::failure(FailureType::ExternalToolError, 'PDFTOPPM_FAILED', $render->stderrTail());
        }

        // pdftoppm은 페이지 번호를 zero-pad — glob 정렬로 수집한다
        $pngPages = glob($prefix.'-*.png') ?: [];
        sort($pngPages, SORT_NATURAL);

        if ($pngPages === []) {
            return JobResult::failure(FailureType::ExternalToolError, 'PDFTOPPM_FAILED', 'no page images were rendered');
        }

        // ── 페이지별 WebP 변환·리사이즈 + PAGE_PREVIEW upsert (page_no 키)
        $created = 0;
        $failed = 0;

        foreach ($pngPages as $i => $pngAbs) {
            $pageNo = $i + 1;
            $ctx->progress->report(
                $job->id, 40.0 + 55.0 * $pageNo / max(1, count($pngPages)),
                "converting page {$pageNo}/{$renderPages}",
            );

            $webpTmp = $workDir.DIRECTORY_SEPARATOR."page_{$pageNo}.webp";
            $convert = $this->runner->run([
                (string) config('workflow.tools.vipsthumbnail'),
                ...$this->compiler->vipsThumbnailArgs($profile, $pngAbs, $webpTmp),
            ], timeoutSec: 120);

            if (! $convert->ok() || ! is_file($webpTmp) || $this->storage->fileSize($webpTmp) === 0) {
                $failed++;
                $ctx->logger->warn($job, "page {$pageNo} conversion failed", ['stderr' => $convert->stderrTail(5)]);

                continue;
            }

            $targetRelative = sprintf(
                'document/%s/%s/%d/page_%d.webp',
                now()->format('Y'), now()->format('m'), $job->content_id, $pageNo,
            );
            $finalAbs = $this->storage->promote(StorageZone::Document, $webpTmp, $targetRelative);

            $this->renditions->upsert([
                'content_id' => $job->content_id,
                'media_file_id' => $master->media_file_id,
                'rendition_type' => $profile->rendition_type->value,
                'storage_zone' => StorageZone::Document->value,
                'variant_key' => $profile->variant_key,
                'path' => $targetRelative,
                'file_size' => $this->storage->fileSize($finalAbs),
                'checksum' => $this->storage->checksumSha256($finalAbs),
                'mime_type' => 'image/webp',
                'page_no' => $pageNo,
                'profile_id' => $profile->id,
            ]);
            $created++;

            $ctx->cancelToken->tick();
        }

        // 1페이지 이상 성공이면 SUCCESS (Job Type Def §3.8 성공 조건)
        if ($created === 0) {
            return JobResult::failure(FailureType::ExternalToolError, 'VIPS_FAILED', 'all page conversions failed');
        }

        $ctx->progress->report($job->id, 100.0, 'preview complete');

        return JobResult::success([
            'page_count' => $pageCount,
            'previews_created' => $created,
            'pages_failed' => $failed,
        ]);
    }
}
