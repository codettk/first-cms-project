<?php

namespace Tests\Feature\Admin;

use App\Models\WorkflowJob;
use App\Models\WorkflowJobLock;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\Admin\WorkflowPermissionService;
use Illuminate\Support\Facades\DB;

/**
 * Controller Service Spec §14·§18 — 권한 계층 · HIGH 개별 부여 · GET 감사 미기록.
 */
class AdminApiPermissionAuditTest extends AdminApiTestCase
{
    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/admin/workflows/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_command_without_permission_gets_403(): void
    {
        $job = WorkflowJob::factory()->failed()->create();

        $this->actingAs($this->viewer())
            ->postJson("/admin/workflows/jobs/{$job->id}/retry", ['reason' => 'test'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    public function test_high_permission_requires_direct_grant(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create();
        $job = WorkflowJob::factory()->running()->create(['worker_id' => $worker->id]);
        WorkflowJobLock::create([
            'job_id' => $job->id, 'locked_by_worker_id' => $worker->id,
            'locked_until' => now()->addMinutes(10),
        ]);

        // 일반 permissions 배열에 HIGH 권한을 넣어도 인정되지 않는다 (hasDirectPermission만)
        $roleBased = $this->admin();
        $roleBased->update(['permissions' => [
            ...WorkflowPermissionService::PERMISSIONS, 'workflow.release_lock',
        ]]);

        $this->actingAs($roleBased)
            ->postJson("/admin/workflows/jobs/{$job->id}/release-lock", ['reason' => 'stuck'])
            ->assertStatus(403);

        $this->actingAs($this->superAdmin())
            ->postJson("/admin/workflows/jobs/{$job->id}/release-lock", ['reason' => 'stuck'])
            ->assertStatus(200);
    }

    public function test_get_requests_never_write_audit_logs(): void
    {
        $job = WorkflowJob::factory()->failed()->create();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->getJson('/admin/workflows/dashboard')->assertOk();
        $this->actingAs($admin)->getJson('/admin/workflows/contents')->assertOk();
        $this->actingAs($admin)->getJson('/admin/workflows/jobs')->assertOk();
        $this->actingAs($admin)->getJson("/admin/workflows/jobs/{$job->id}")->assertOk();
        $this->actingAs($admin)->getJson('/admin/workflows/workers')->assertOk();

        $this->assertSame(0, DB::table('admin_audit_logs')->count(), 'GET 요청이 감사 로그를 기록했다');
    }

    public function test_commands_write_audit_logs_with_actor_and_reason(): void
    {
        $job = WorkflowJob::factory()->failed()->create();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson("/admin/workflows/jobs/{$job->id}/retry", ['reason' => '재시도 사유'])
            ->assertOk();

        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'job.retry',
            'target_id' => $job->id,
            'before_state' => 'FAILED',
            'after_state' => 'RETRY',
            'reason' => '재시도 사유',
        ]);
    }

    public function test_reason_required_actions_reject_blank_reason(): void
    {
        $job = WorkflowJob::factory()->ready()->create();

        $this->actingAs($this->admin())
            ->postJson("/admin/workflows/jobs/{$job->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['reason']]]);

        $this->assertSame('READY', $job->fresh()->status->value);
        $this->assertSame(0, DB::table('admin_audit_logs')->count());
    }

    public function test_not_found_is_mapped(): void
    {
        $this->actingAs($this->admin())
            ->getJson('/admin/workflows/jobs/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
