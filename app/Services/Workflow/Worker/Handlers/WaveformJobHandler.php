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
 * WAVEFORM — 오디오 파형 peaks JSON 생성 (Job Type Def §3.6 · Transcode Profile Spec §6).
 * audiowaveform이 MASTER를 읽어 JSON peaks를 산출한다 — 선택 작업이라 실패해도
 * PUBLISH를 차단하지 않는다 (SM Spec §7). AAC 컨테이너 등 미지원 포맷은 도구 오류로 종결.
 */
class WaveformJobHandler extends AbstractJobHandler
{
    protected const string JOB_TYPE = 'WAVEFORM';

    /** 템플릿 step config에 profile이 없을 때의 기본 — seed 12종 계약 내 코드 */
    private const string DEFAULT_PROFILE = 'AUDIO_WAVEFORM_JSON';

    public function __construct(
        private readonly MediaStorageService $storage,
        private readonly RenditionService $renditions,
        private readonly ProfileCompiler $compiler,
        private readonly ToolRunner $runner,
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

        $outputTmp = $this->storage->tempFilePath(
            StorageZone::Proxy, $job->id, "{$job->content_id}_{$profile->variant_key}.{$profile->output_format}"
        );

        $command = [
            (string) config('workflow.tools.audiowaveform'),
            ...$this->compiler->audiowaveformArgs($profile, $inputAbs, $outputTmp),
        ];

        $ctx->logger->info($job, 'waveform generation started', ['profile' => $profile->code], implode(' ', $command));
        $ctx->progress->report($job->id, 10.0, 'generating waveform');

        $result = $this->runner->run($command, timeoutSec: max(30, $job->timeout_sec - 30));

        if ($result->exitCode === 124) {
            return JobResult::failure(FailureType::ExternalToolError, 'TOOL_TIMEOUT', $result->stderrTail());
        }

        if (! $result->ok() || ! is_file($outputTmp) || $this->storage->fileSize($outputTmp) === 0) {
            return JobResult::failure(FailureType::ExternalToolError, 'AUDIOWAVEFORM_FAILED', $result->stderrTail());
        }

        // 검증: peaks JSON 구조 (Transcode Profile Spec §6 — JSON peaks)
        $decoded = json_decode((string) file_get_contents($outputTmp), true);

        if (! is_array($decoded) || ! isset($decoded['data']) || ! is_array($decoded['data']) || $decoded['data'] === []) {
            return JobResult::failure(FailureType::ExternalToolError, 'AUDIOWAVEFORM_FAILED', 'waveform output is not valid peaks JSON');
        }

        $targetRelative = sprintf(
            'proxy/%s/%s/%d_%s.%s',
            now()->format('Y'), now()->format('m'),
            $job->content_id, $profile->code, $profile->output_format,
        );

        $finalAbs = $this->storage->promote(StorageZone::Proxy, $outputTmp, $targetRelative);

        $this->renditions->upsert([
            'content_id' => $job->content_id,
            'media_file_id' => $master->media_file_id,
            'rendition_type' => $profile->rendition_type->value,
            'storage_zone' => StorageZone::Proxy->value,
            'variant_key' => $profile->variant_key,
            'path' => $targetRelative,
            'file_size' => $this->storage->fileSize($finalAbs),
            'checksum' => $this->storage->checksumSha256($finalAbs),
            'mime_type' => 'application/json',
            'metadata' => ['peaks' => count($decoded['data'])],
            'profile_id' => $profile->id,
        ]);

        $ctx->progress->report($job->id, 100.0, 'waveform generated');

        return JobResult::success([
            'waveform_path' => $targetRelative,
            'profile' => $profile->code,
            'peaks' => count($decoded['data']),
        ]);
    }
}
