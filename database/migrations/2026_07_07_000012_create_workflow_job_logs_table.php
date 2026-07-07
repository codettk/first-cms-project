<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_job_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_id')->constrained('workflow_jobs');
            $t->integer('attempt_no')->default(1);
            $t->string('level', 10)->default('INFO');
            $t->text('command')->nullable();
            $t->text('stdout')->nullable();
            $t->text('stderr')->nullable();
            $t->text('message')->nullable();
            $t->jsonb('detail')->nullable();
            $t->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::statement("ALTER TABLE workflow_job_logs ADD CONSTRAINT chk_logs_level
            CHECK (level IN ('DEBUG','INFO','WARN','ERROR'))");
        DB::statement('CREATE INDEX idx_logs_job ON workflow_job_logs (job_id, attempt_no, created_at)');
        DB::statement("CREATE INDEX idx_logs_error ON workflow_job_logs (created_at DESC) WHERE level = 'ERROR'");
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_job_logs');
    }
};
