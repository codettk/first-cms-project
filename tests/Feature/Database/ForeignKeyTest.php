<?php

namespace Tests\Feature\Database;

use App\Models\WorkflowInstance;
use App\Models\WorkflowJob;
use App\Models\WorkflowWorkerAgent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration Seed Spec §15 — FK 제약: 없는 content_id job INSERT 실패, 순환 FK(current_job_id) 동작.
 */
class ForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_insert_with_missing_content_fails(): void
    {
        $instance = WorkflowInstance::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('workflow_jobs')->insert([
            'instance_id' => $instance->id,
            'content_id' => 99999999,
            'job_type' => 'TM',
            'status' => 'WAITING',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_circular_fk_current_job_id_works(): void
    {
        $worker = WorkflowWorkerAgent::factory()->online()->create();
        $job = WorkflowJob::factory()->running()->create(['worker_id' => $worker->id]);

        $worker->update(['current_job_id' => $job->id]);

        $this->assertSame($job->id, $worker->fresh()->currentJob->id);
    }

    public function test_current_job_id_with_missing_job_fails(): void
    {
        $worker = WorkflowWorkerAgent::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('workflow_worker_agents')
            ->where('id', $worker->id)
            ->update(['current_job_id' => 99999999]);
    }

    public function test_dependency_self_reference_is_rejected(): void
    {
        $job = WorkflowJob::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('workflow_job_dependencies')->insert([
            'job_id' => $job->id,
            'depends_on_job_id' => $job->id, // CHECK job_id <> depends_on_job_id
        ]);
    }
}
