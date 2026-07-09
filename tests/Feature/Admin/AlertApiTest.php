<?php

namespace Tests\Feature\Admin;

use App\Mail\WorkflowAlertMail;
use App\Models\WorkflowAlert;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Admin\AlertService;
use App\Services\Workflow\Scheduler\WorkflowSchedulerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * 알림 영속화·확인·이메일 발송 — ADR-0006 · Admin API Spec §21 · Wireframe §13.
 */
class AlertApiTest extends AdminApiTestCase
{
    private function makeAlert(array $attributes = []): WorkflowAlert
    {
        return WorkflowAlert::create([
            'severity' => 'HIGH',
            'event_type' => 'REQUIRED_JOB_FAILED',
            'message' => '테스트 알림',
            'action_link' => '/admin/workflows/jobs/1',
            'created_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_alert_list_returns_contract_items_with_filters(): void
    {
        $open = $this->makeAlert();
        $this->makeAlert([
            'severity' => 'MED', 'event_type' => 'TIMEOUT',
            'acknowledged_at' => now(), 'acknowledged_by' => $this->admin()->id,
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson('/admin/workflows/alerts?acknowledged=0')
            ->assertOk()
            ->assertJsonStructure(['success', 'data' => [
                ['alert_id', 'severity', 'event_type', 'message', 'related_content_id',
                    'related_job_id', 'related_worker_id', 'created_at',
                    'acknowledged_at', 'acknowledged_by', 'action_link'],
            ]]);

        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame($open->id, $items[0]['alert_id']);

        // severity 필터
        $this->actingAs($this->admin())
            ->getJson('/admin/workflows/alerts?severity=MED')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_ack_is_idempotent_and_audited(): void
    {
        $alert = $this->makeAlert();
        $admin = $this->admin();

        $first = $this->actingAs($admin)
            ->postJson("/admin/workflows/alerts/{$alert->id}/ack", ['note' => '재시도 완료'])
            ->assertOk();

        $ackedAt = $first->json('data.acknowledged_at');
        $this->assertNotNull($ackedAt);
        $this->assertSame($admin->id, $first->json('data.acknowledged_by'));
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_user_id' => $admin->id, 'action' => 'alert.ack', 'target_id' => $alert->id,
        ]);

        // 멱등 — 재호출은 200 + 기존 acknowledged_at, 감사 재기록 없음
        $second = $this->actingAs($admin)
            ->postJson("/admin/workflows/alerts/{$alert->id}/ack")
            ->assertOk();
        $this->assertSame($ackedAt, $second->json('data.acknowledged_at'));
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'alert.ack')->count());
    }

    public function test_scheduler_raises_required_failure_alert_with_email(): void
    {
        Mail::fake();
        config(['workflow.alerts.mail_to' => ['ops@example.com']]);

        $job = WorkflowJob::factory()->failed()->create(['is_required' => true]);

        app(WorkflowSchedulerService::class)->tick();

        $alert = WorkflowAlert::query()->where('event_type', 'REQUIRED_JOB_FAILED')->first();
        $this->assertNotNull($alert, '필수 실패 알림이 생성되지 않았다');
        $this->assertSame('HIGH', $alert->severity);
        $this->assertSame($job->id, (int) $alert->related_job_id);
        $this->assertSame("/admin/workflows/jobs/{$job->id}", $alert->action_link);

        Mail::assertSent(WorkflowAlertMail::class, 1);

        // 미확인 동일 이벤트·동일 대상 중복 억제 (ADR-0006)
        $this->assertNull(app(AlertService::class)->raise(
            'HIGH', 'REQUIRED_JOB_FAILED', 'dup',
            contentId: (int) $alert->related_content_id, jobId: $job->id,
        ));
        $this->assertSame(1, WorkflowAlert::query()->where('event_type', 'REQUIRED_JOB_FAILED')->count());
    }

    public function test_scheduler_raises_worker_offline_alert_without_email(): void
    {
        Mail::fake();
        config(['workflow.alerts.mail_to' => ['ops@example.com']]);

        $worker = WorkflowWorkerAgent::factory()->create([
            'status' => 'BUSY', 'last_heartbeat_at' => now()->subMinutes(5),
        ]);

        app(WorkflowSchedulerService::class)->tick();

        $alert = WorkflowAlert::query()->where('event_type', 'WORKER_OFFLINE')->first();
        $this->assertNotNull($alert);
        $this->assertSame($worker->id, (int) $alert->related_worker_id);
        $this->assertStringContainsString($worker->worker_name, $alert->message);

        // WORKER_OFFLINE은 이메일 채널이 아니다 (Wireframe §13 — 배너 + 알림센터)
        Mail::assertNotSent(WorkflowAlertMail::class);

        // 재틱에도 중복 없음
        app(WorkflowSchedulerService::class)->tick();
        $this->assertSame(1, WorkflowAlert::query()->where('event_type', 'WORKER_OFFLINE')->count());
    }
}
