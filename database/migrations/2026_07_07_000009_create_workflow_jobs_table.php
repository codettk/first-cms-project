<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instance_id')->constrained('workflow_instances');
            $t->foreignId('content_id')->constrained('contents');
            $t->string('job_type', 30);
            $t->string('status', 20)->default('WAITING');
            $t->boolean('is_required')->default(true);
            $t->smallInteger('priority')->default(100);
            $t->integer('retry_count')->default(0);
            $t->integer('max_retry')->default(3);
            $t->integer('timeout_sec')->default(600);
            $t->timestampTz('next_attempt_at')->nullable();
            $t->jsonb('payload')->nullable();
            $t->jsonb('result')->nullable();
            $t->string('fail_reason_code', 50)->nullable();
            $t->foreignId('worker_id')->nullable()->constrained('workflow_worker_agents');
            $t->timestampTz('cancel_requested_at')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestampsTz();
            $t->unique(['instance_id', 'job_type']);
        });

        DB::statement("ALTER TABLE workflow_jobs ADD CONSTRAINT chk_wf_jobs_status CHECK (status IN
            ('WAITING','READY','RUNNING','SUCCESS','FAILED','RETRY','SKIPPED','CANCELED','TIMEOUT'))");
        DB::statement('ALTER TABLE workflow_jobs ADD CONSTRAINT chk_wf_jobs_priority
            CHECK (priority BETWEEN 0 AND 1000)');
        DB::statement("ALTER TABLE workflow_jobs ADD CONSTRAINT chk_wf_jobs_job_type
            CHECK (job_type IN ('TM','VERIFY','MA','TC','IMAGE_TC','AUDIO_TC','CA','DOC_PREVIEW','OCR',
                'TEXT_EXTRACT','INDEX','PUBLISH','CLEANUP','WAVEFORM','STT','AI_ANALYSIS'))");

        // 큐 소비·타임아웃·실패 목록 인덱스 (Migration Seed Spec §6)
        DB::statement("CREATE INDEX idx_jobs_queue ON workflow_jobs (status, next_attempt_at)
            WHERE status IN ('READY','RETRY')");
        DB::statement("CREATE INDEX idx_jobs_running ON workflow_jobs (started_at)
            WHERE status = 'RUNNING'");
        DB::statement("CREATE INDEX idx_jobs_failed ON workflow_jobs (finished_at DESC)
            WHERE status = 'FAILED'");
        DB::statement('CREATE INDEX idx_jobs_content ON workflow_jobs (content_id)');
        DB::statement('CREATE INDEX idx_jobs_instance ON workflow_jobs (instance_id)');
        DB::statement('CREATE INDEX idx_jobs_worker ON workflow_jobs (worker_id, status)');

        // agents ↔ jobs 순환 FK — current_job_id는 여기서 후행 추가
        DB::statement('ALTER TABLE workflow_worker_agents ADD CONSTRAINT fk_workers_current_job
            FOREIGN KEY (current_job_id) REFERENCES workflow_jobs (id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workflow_worker_agents DROP CONSTRAINT IF EXISTS fk_workers_current_job');
        Schema::dropIfExists('workflow_jobs');
    }
};
