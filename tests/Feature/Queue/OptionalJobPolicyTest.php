<?php

namespace Tests\Feature\Queue;

use App\Models\Content;
use App\Models\WorkflowInstance;
use App\Models\WorkflowJob;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Queue\JobClaimService;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 미구현 선택 job(OCR·WAVEFORM·STT) 잔여 대기 정책 — SM Spec §7·§8 · WBS §M6.
 *
 * Handler가 없는 선택 job(is_required=false)은 Scheduler가 READY로 승격하되,
 * 어떤 worker의 supported_job_types에도 포함되지 않아 claim되지 않고 READY로
 * 대기한다. PUBLISH 판정은 is_required=true만 집계하므로(SM Spec §8) 잔여
 * 대기는 파이프라인을 차단하지 않는다 — 운영상 의도된 상태다
 * (docs/ops/workflow-release-gate-checklist.md §8).
 */
class OptionalJobPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** AppServiceProvider::MVP_HANDLERS와 동일한 12종 — MvpScopeGuardTest 참조 */
    private const array IMPLEMENTED_HANDLER_TYPES = [
        'TM', 'VERIFY', 'MA', 'TC', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP',
        'IMAGE_TC', 'AUDIO_TC', 'DOC_PREVIEW', 'TEXT_EXTRACT',
    ];

    private WorkflowSchedulerService $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->scheduler = app(WorkflowSchedulerService::class);
    }

    /** 템플릿 인스턴스 생성 후 [instance, content] 반환 */
    private function makeInstance(string $templateCode): array
    {
        $content = Content::factory()->processing()->create();
        $template = WorkflowTemplate::where('code', $templateCode)->firstOrFail();
        $instance = DB::transaction(
            fn () => app(WorkflowInstanceFactory::class)->createForContent($content, $template)
        );

        return [$instance, $content];
    }

    /** trigger 허용 경로를 따라 상태를 강제 설정 (테스트 픽스처 전용) */
    private function forceStatus(WorkflowJob $job, string ...$path): void
    {
        foreach ($path as $status) {
            DB::table('workflow_jobs')->where('id', $job->id)->update(['status' => $status]);
        }
    }

    private function jobOf(WorkflowInstance $instance, string $type): WorkflowJob
    {
        return $instance->jobs()->where('job_type', $type)->firstOrFail();
    }

    public function test_unimplemented_optional_jobs_wait_in_ready_without_being_claimed(): void
    {
        [$instance] = $this->makeInstance('AUDIO_INGEST');

        foreach (['TM', 'VERIFY', 'MA', 'AUDIO_TC', 'INDEX'] as $type) {
            $this->forceStatus($this->jobOf($instance, $type), 'READY', 'RUNNING', 'SUCCESS');
        }

        $this->scheduler->tick();

        // 선행(AUDIO_TC) SUCCESS — Scheduler는 선택 job도 READY로 승격한다
        $this->assertSame('READY', $this->jobOf($instance, 'WAVEFORM')->status->value);
        $this->assertSame('READY', $this->jobOf($instance, 'STT')->status->value);
        // PUBLISH 판정은 필수 job만 집계 — 선택 job READY 잔존은 비차단 (SM Spec §8)
        $this->assertSame('READY', $this->jobOf($instance, 'PUBLISH')->status->value);

        $this->forceStatus($this->jobOf($instance, 'PUBLISH'), 'RUNNING', 'SUCCESS');
        $this->forceStatus($this->jobOf($instance, 'CLEANUP'), 'READY', 'RUNNING', 'SUCCESS');

        // 구현 12종 전부를 지원하는 worker도 미구현 선택 job은 claim하지 않는다
        $worker = WorkflowWorkerAgent::factory()->online()->create([
            'supported_job_types' => self::IMPLEMENTED_HANDLER_TYPES,
        ]);
        $this->assertNull(app(JobClaimService::class)->tryClaim($worker));

        // 반복 tick에도 재시도 회계·SKIPPED 전파 없이 READY 그대로 대기한다
        $this->scheduler->tick();
        foreach (['WAVEFORM', 'STT'] as $type) {
            $job = $this->jobOf($instance, $type);
            $this->assertSame('READY', $job->status->value);
            $this->assertSame(0, $job->retry_count);
            $this->assertDatabaseMissing('workflow_job_histories', [
                'job_id' => $job->id, 'to_status' => 'SKIPPED',
            ]);
        }
    }

    public function test_optional_ocr_is_promoted_even_after_instance_success(): void
    {
        [$instance] = $this->makeInstance('DOCUMENT_INGEST');

        $required = ['TM', 'VERIFY', 'MA', 'DOC_PREVIEW', 'TEXT_EXTRACT', 'CA', 'INDEX', 'PUBLISH', 'CLEANUP'];
        foreach ($required as $type) {
            $this->forceStatus($this->jobOf($instance, $type), 'READY', 'RUNNING', 'SUCCESS');
        }
        // PUBLISH SUCCESS 시점의 인스턴스 종결 재현 (테스트 픽스처 전용)
        DB::table('workflow_instances')->where('id', $instance->id)->update(['status' => 'SUCCESS']);

        $this->scheduler->tick();

        // 인스턴스 SUCCESS 이후에도 선택 job은 READY로 승격돼 대기한다
        $ocr = $this->jobOf($instance, 'OCR');
        $this->assertSame('READY', $ocr->status->value);
        $this->assertSame(0, $ocr->retry_count);
    }
}
