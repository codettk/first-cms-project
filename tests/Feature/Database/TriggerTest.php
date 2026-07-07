<?php

namespace Tests\Feature\Database;

use App\Models\Content;
use App\Models\WorkflowInstance;
use App\Models\WorkflowJob;
use App\Models\WorkflowJobHistory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration Seed Spec §15 — invalid transition trigger · DELETED 가드 · append-only trigger.
 */
class TriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(); // 전이 trigger는 workflow_job_allowed_transitions seed를 참조한다
    }

    public function test_allowed_transition_waiting_to_ready_succeeds(): void
    {
        $job = WorkflowJob::factory()->create(); // WAITING

        DB::table('workflow_jobs')->where('id', $job->id)->update(['status' => 'READY']);

        $this->assertSame('READY', $job->fresh()->status->value);
    }

    public function test_invalid_transition_success_to_running_is_blocked(): void
    {
        $job = WorkflowJob::factory()->success()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('invalid job transition');
        DB::table('workflow_jobs')->where('id', $job->id)->update(['status' => 'RUNNING']);
    }

    public function test_invalid_transition_waiting_to_running_is_blocked(): void
    {
        $job = WorkflowJob::factory()->create(); // WAITING — READY 우회 금지

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('invalid job transition');
        DB::table('workflow_jobs')->where('id', $job->id)->update(['status' => 'RUNNING']);
    }

    public function test_non_status_update_passes_transition_trigger(): void
    {
        $job = WorkflowJob::factory()->success()->create();

        DB::table('workflow_jobs')->where('id', $job->id)->update(['priority' => 200]);

        $this->assertSame(200, $job->fresh()->priority);
    }

    public function test_job_insert_for_deleted_content_is_blocked(): void
    {
        $content = Content::factory()->deleted()->create();
        $instance = WorkflowInstance::factory()->create(['content_id' => $content->id]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cannot create job for DELETED content');
        DB::table('workflow_jobs')->insert([
            'instance_id' => $instance->id,
            'content_id' => $content->id,
            'job_type' => 'TM',
            'status' => 'WAITING',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_histories_update_is_blocked(): void
    {
        $history = $this->makeHistory();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('workflow_job_histories')->where('id', $history->id)->update(['note' => 'tampered']);
    }

    public function test_histories_delete_is_blocked(): void
    {
        $history = $this->makeHistory();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('workflow_job_histories')->where('id', $history->id)->delete();
    }

    public function test_audit_logs_update_is_blocked(): void
    {
        $id = $this->makeAuditLog();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('admin_audit_logs')->where('id', $id)->update(['reason' => 'tampered']);
    }

    public function test_audit_logs_delete_is_blocked(): void
    {
        $id = $this->makeAuditLog();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('admin_audit_logs')->where('id', $id)->delete();
    }

    public function test_updated_at_is_refreshed_by_trigger(): void
    {
        $job = WorkflowJob::factory()->create();
        DB::table('workflow_jobs')->where('id', $job->id)->update(['updated_at' => now()->subDay()]);

        DB::table('workflow_jobs')->where('id', $job->id)->update(['priority' => 300]);

        $this->assertTrue($job->fresh()->updated_at->greaterThan(now()->subHour()));
    }

    private function makeHistory(): WorkflowJobHistory
    {
        $job = WorkflowJob::factory()->create();

        return WorkflowJobHistory::query()->create([
            'job_id' => $job->id,
            'from_status' => null,
            'to_status' => 'WAITING',
            'actor' => 'system',
        ]);
    }

    private function makeAuditLog(): int
    {
        return DB::table('admin_audit_logs')->insertGetId([
            'actor_user_id' => 1,
            'action' => 'job.retry',
            'target_type' => 'job',
            'target_id' => 1,
            'reason' => 'test',
            'ip_address' => '127.0.0.1',
        ]);
    }
}
