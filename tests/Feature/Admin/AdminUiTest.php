<?php

namespace Tests\Feature\Admin;

use App\Models\WorkflowJob;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;

/**
 * Admin UI (MVP 4화면) — 콘솔 페이지 서빙 · available_actions 전용 버튼 원칙 ·
 * HIGH 버튼 기본 미노출 · 운영 시나리오(실패 확인 → 사유 열람 → 재시도 → READY 회복).
 */
class AdminUiTest extends AdminApiTestCase
{
    public function test_console_requires_authentication_and_view_permission(): void
    {
        // 미인증 차단 — 게스트는 루트로 리다이렉트 (스켈레톤에 로그인 화면 없음)
        $this->get('/admin/workflow-console')->assertRedirect('/');

        $this->actingAs($this->viewer())
            ->get('/admin/workflow-console')
            ->assertOk()
            ->assertSee('워크플로우 대시보드', escape: false);
    }

    public function test_console_renders_action_buttons_only_from_server_actions(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/workflow-console');

        // 버튼은 available_actions 배열에서만 생성 — 핵심 원칙이 코드에 존재하는지 확인
        $response->assertSee('available_actions', escape: false);
        $response->assertSee('actionButtons', escape: false);
    }

    public function test_high_risk_actions_are_hidden_by_default(): void
    {
        $response = $this->actingAs($this->superAdmin())->get('/admin/workflow-console');

        // HIGH 액션은 렌더링 필터로 차단 (MVP Scope §2 — API 존재·화면 미노출)
        $response->assertSee("HIDDEN_HIGH_ACTIONS = ['release_lock', 'disable', 'enable', 'clear_error']", escape: false);
        // Worker 관리 조작 UI 없음 안내
        $response->assertSee('기본 화면에 노출하지 않습니다', escape: false);
    }

    public function test_console_never_builds_master_direct_paths(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/workflow-console');

        // Master는 path_masked 표시만 — 직접 경로 조립 코드가 없어야 한다
        $response->assertSee('path_masked', escape: false);
        $this->assertStringNotContainsString('master/2026', $response->getContent());
        $this->assertStringNotContainsString("'/master/", $response->getContent());
    }

    /**
     * 운영 시나리오 (Roadmap Phase 7 완료 기준) — UI가 호출하는 API 경로 그대로:
     * 실패 Job 확인(목록) → 사유 열람(상세 histories/logs) → 재시도 → Scheduler 재개 → READY 회복.
     */
    public function test_operator_scenario_fail_inspect_retry_recover(): void
    {
        $admin = $this->admin();
        $job = WorkflowJob::factory()->failed()->create([
            'fail_reason_code' => 'FFMPEG_FAILED', 'retry_count' => 2, 'max_retry' => 3,
        ]);

        // ① 실패 Job 확인 — 실패 목록 (UI: #/jobs?status=FAILED)
        $list = $this->actingAs($admin)->getJson('/admin/workflows/jobs?status=FAILED')->assertOk();
        $item = collect($list->json('data'))->firstWhere('job_id', $job->id);
        $this->assertNotNull($item);
        $this->assertSame('FFMPEG_FAILED', $item['fail_reason_code']);
        $this->assertContains('retry', $item['available_actions']);

        // ② 사유 열람 — Job 상세 (UI: #/jobs/{id})
        $detail = $this->actingAs($admin)->getJson("/admin/workflows/jobs/{$job->id}")->assertOk();
        $this->assertSame('FAILED', $detail->json('data.job.status'));

        // ③ 재시도 (UI: 재시도 버튼 → POST /jobs/{id}/retry)
        $this->actingAs($admin)
            ->postJson("/admin/workflows/jobs/{$job->id}/retry", ['reason' => '도구 오류 재시도'])
            ->assertOk()
            ->assertJsonPath('data.status', 'RETRY');

        // ④ Scheduler 재개 — RETRY → READY 회복
        app(WorkflowSchedulerService::class)->tick();

        $this->assertSame('READY', $job->fresh()->status->value);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'job.retry', 'target_id' => $job->id]);
    }
}
