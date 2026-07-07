<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_worker_agents', function (Blueprint $t) {
            $t->id();
            $t->string('worker_name', 100)->unique();
            $t->string('worker_type', 30);
            $t->jsonb('supported_job_types');
            $t->string('hostname', 255);
            $t->ipAddress('ip_address');
            $t->string('status', 15)->default('OFFLINE');
            $t->timestampTz('last_heartbeat_at')->nullable();
            $t->foreignId('current_job_id')->nullable(); // FK는 workflow_jobs 생성 migration에서 후행 추가 (순환 FK)
            $t->integer('max_concurrency')->default(1);
            $t->boolean('gpu_available')->default(false);
            $t->integer('cpu_count')->nullable();
            $t->integer('memory_limit_mb')->nullable();
            $t->string('version', 30)->nullable();
            $t->timestampsTz();
        });

        DB::statement("ALTER TABLE workflow_worker_agents ADD CONSTRAINT chk_workers_status
            CHECK (status IN ('ONLINE','OFFLINE','BUSY','DISABLED','ERROR'))");
        DB::statement('CREATE INDEX idx_workers_status ON workflow_worker_agents (status, worker_type)');
        DB::statement('CREATE INDEX idx_workers_heartbeat ON workflow_worker_agents (last_heartbeat_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_worker_agents');
    }
};
