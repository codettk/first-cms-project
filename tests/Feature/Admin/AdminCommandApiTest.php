<?php

namespace Tests\Feature\Admin;

use App\Enums\JobType;
use App\Models\Content;
use App\Models\WorkflowJob;
use App\Models\WorkflowJobLock;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowWorkerAgent;
use App\Services\Workflow\WorkflowInstanceFactory;
use Illuminate\Support\Facades\DB;

/**
 * Admin Command API — 409 상태 충돌 · 멱등 · 배치 부분 성공 · Worker 조작 (Spec §10·§18).
 */
class AdminCommandApiTest extends AdminApiTestCase
{
    public function test_retry_failed_job_transitions_to_retry(): void
    {
        $job = WorkflowJob::factory()->failed()->create(['retry_count' => 3]);

        $this->actingAs($this->admin())
            ->postJson("/admin/workflows/jobs/{$job->id}/retry", ['reason' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.status', 'RETRY')
            ->assertJsonPath('data.retry_count', 0); // reset_retry_count 기본 true

        $fresh = $job->fresh();
        $this->assertSame('RETRY', $fresh->status->value);
        $this->assertNotNull($fresh->next_attempt_at);
        $this->assertDatabaseHas('workflow_job_histories', [
            'job_id' => $job->id, 'from_status' => 'FAILED', 'to_status' => 'RETRY',
        ]);
    }

    public function test_retry_on_success_job_returns_409_with_current_state(): void
    {
        $job = WorkflowJob::factory()->success()->create();

        $this->actingAs($this->admin())
            ->postJson("/admin/workflows/jobs/{$job->id}/retry", ['reason' => 'no'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION')
            ->assertJsonPath('error.current_state', 'SUCCESS');
    }

    public function test_retry_batch_allows_partial_success(): void
    {
        $failed = WorkflowJob::factory()->failed()->create();
        $success = WorkflowJob::factory()->success()->create();

        $response = $this->actingAs($this->admin())
            ->postJson('/admin/workflows/jobs/retry-batch', [
                'job_ids' => [$failed->id, $success->id, 999999],
                'reason' => '일괄 재시도',
            ])
            ->assertOk();

        $response->assertJsonPath('data.succeeded', 1)
            ->assertJsonPath('data.failed', 2);

        $this->assertSame('RETRY', $failed->fresh()->status->value);
        $this->assertSame('SUCCESS', $success->fresh()->status->value);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'job.retry_batch']);
    }

    public function test_cancel_waiting_job_immediately(): void
    {
        $job = WorkflowJob::factory()->create(); // WAITING

        $this->actingAs($this->admin())
            ->postJson("/admin/workflows/jobs/{$job->id}/cancel", ['reason' => '불필요'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELED');

        $this->assertSame('CANCELED', $job->fresh()->status->value);
    }

    public function test_cancel_running_job_marks_request_and_returns_202(): void
    {
        $job = WorkflowJob::factory()->running()->create();

        $this->actingAs($this->admin())
            ->postJson("/admin/workflows/jobs/{$job->id}/cancel", ['reason' => '중단'])
            ->assertStatus(202)
            ->assertJsonPath('data.cancel_requested', true);

        $fresh = $job->fresh();
        $this->assertSame('RUNNING', $fresh->status->value); // Worker/Scheduler가 이후 전이
        $this->assertNotNull($fresh->cancel_requested_at);
    }

    public function test_release_lock_reclaims_and_moves_job_to_retry(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create();
        $job = WorkflowJob::factory()->running()->create(['worker_id' => $worker->id]);
        WorkflowJobLock::create([
            'job_id' => $job->id, 'locked_by_worker_id' => $worker->id,
            'locked_until' => now()->addMinutes(10),
        ]);

        $this->actingAs($this->superAdmin())
            ->postJson("/admin/workflows/jobs/{$job->id}/release-lock", ['reason' => '멈춤'])
            ->assertOk()
            ->assertJsonPath('data.status', 'RETRY');

        $this->assertDatabaseMissing('workflow_job_locks', ['job_id' => $job->id]);
        $this->assertSame('RETRY', $job->fresh()->status->value);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'job.release_lock']);
    }

    public function test_release_lock_without_lock_returns_409(): void
    {
        $job = WorkflowJob::factory()->running()->create();

        $this->actingAs($this->superAdmin())
            ->postJson("/admin/workflows/jobs/{$job->id}/release-lock", ['reason' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'JOB_LOCK_NOT_FOUND');
    }

    public function test_update_priority_on_ready_job(): void
    {
        $job = WorkflowJob::factory()->ready()->create();

        $this->actingAs($this->admin())
            ->patchJson("/admin/workflows/jobs/{$job->id}/priority", ['priority' => 300])
            ->assertOk()
            ->assertJsonPath('data.priority', 300);

        $this->assertSame(300, $job->fresh()->priority);
        // 상태 전이가 아니므로 history 없음 — audit만
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'job.update_priority']);
        $this->assertDatabaseMissing('workflow_job_histories', ['job_id' => $job->id]);
    }

    public function test_update_priority_on_running_job_conflicts(): void
    {
        $job = WorkflowJob::factory()->running()->create();

        $this->actingAs($this->admin())
            ->patchJson("/admin/workflows/jobs/{$job->id}/priority", ['priority' => 300])
            ->assertStatus(409);
    }

    public function test_worker_disable_enable_cycle(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson("/admin/workflows/workers/{$worker->id}/disable", ['reason' => '점검'])
            ->assertOk()
            ->assertJsonPath('data.status', 'DISABLED');

        $this->actingAs($admin)
            ->postJson("/admin/workflows/workers/{$worker->id}/enable", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'ONLINE');

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'worker.disable', 'reason' => '점검']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'worker.enable']);
    }

    public function test_worker_clear_error_requires_error_state(): void
    {
        $errorWorker = WorkflowWorkerAgent::factory()->create(['status' => 'ERROR']);
        $onlineWorker = WorkflowWorkerAgent::factory()->online()->create();
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->postJson("/admin/workflows/workers/{$errorWorker->id}/clear-error", ['reason' => '조치 완료'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ONLINE');

        $this->actingAs($admin)
            ->postJson("/admin/workflows/workers/{$onlineWorker->id}/clear-error", ['reason' => 'x'])
            ->assertStatus(409);
    }

    public function test_content_reprocess_creates_new_instance(): void
    {
        $content = Content::factory()->ready()->create(); // READY VIDEO

        $this->actingAs($this->admin())
            ->postJson("/admin/workflows/contents/{$content->id}/reprocess", [
                'mode' => 'full', 'reason' => '프로파일 갱신',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'PROCESSING');

        $this->assertSame('PROCESSING', $content->fresh()->status->value);
        $instance = $content->instances()->latest('id')->first();
        $this->assertNotNull($instance);
        $this->assertSame(8, $instance->jobs()->count());
        $this->assertSame([200], $instance->jobs()->distinct()->pluck('priority')->all()); // 재처리 tier
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'content.reprocess']);
    }

    public function test_content_reprocess_failed_from_reuses_previous_successes(): void
    {
        $content = Content::factory()->processing()->create();
        $template = WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();
        $previous = DB::transaction(
            fn () => app(WorkflowInstanceFactory::class)->createForContent($content, $template)
        );

        // TM·VERIFY·MA 성공, TC 실패로 종결된 상황 재구성
        foreach (['TM', 'VERIFY', 'MA'] as $type) {
            foreach (['READY', 'RUNNING', 'SUCCESS'] as $status) {
                DB::table('workflow_jobs')->where('instance_id', $previous->id)
                    ->where('job_type', $type)->update(['status' => $status]);
            }
        }
        foreach (['READY', 'RUNNING', 'FAILED'] as $status) {
            DB::table('workflow_jobs')->where('instance_id', $previous->id)
                ->where('job_type', 'TC')->update(['status' => $status]);
        }
        DB::table('workflow_instances')->where('id', $previous->id)->update(['status' => 'FAILED']);
        DB::table('contents')->where('id', $content->id)->update(['status' => 'FAILED']);

        $this->actingAs($this->admin())
            ->postJson("/admin/workflows/contents/{$content->id}/reprocess", [
                'mode' => 'failed_from', 'reason' => '실패 지점부터',
            ])
            ->assertOk();

        $newInstance = $content->instances()->latest('id')->first();
        $this->assertNotSame($previous->id, $newInstance->id);

        $statuses = $newInstance->jobs()->pluck('status', 'job_type');
        foreach (['TM', 'VERIFY', 'MA'] as $type) {
            $this->assertSame('SUCCESS', $statuses[$type]->value, "{$type}는 재사용(SUCCESS)이어야 한다");
        }
        $this->assertSame('WAITING', $statuses['TC']->value);
    }

    public function test_content_cancel_cancels_pipeline(): void
    {
        $content = Content::factory()->processing()->create();
        $template = WorkflowTemplate::where('code', 'VIDEO_INGEST')->firstOrFail();
        $instance = DB::transaction(
            fn () => app(WorkflowInstanceFactory::class)->createForContent($content, $template)
        );

        $this->actingAs($this->admin())
            ->postJson("/admin/workflows/contents/{$content->id}/cancel", ['reason' => '업로드 취소'])
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED');

        $this->assertSame('FAILED', $content->fresh()->status->value);
        $this->assertSame('CANCELED', $instance->fresh()->status->value);
        $this->assertSame(8, $instance->jobs()->where('status', 'CANCELED')->count());
    }
}
