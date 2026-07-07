<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 진행률은 workflow_jobs가 아니라 이 테이블에 저장한다 (Queue Spec §10 3원칙)
        Schema::create('workflow_job_progresses', function (Blueprint $t) {
            $t->foreignId('job_id')->primary()->constrained('workflow_jobs');
            $t->decimal('progress_percent', 5, 2)->default(0);
            $t->string('progress_message', 500)->nullable();
            $t->integer('estimated_remaining_sec')->nullable();
            $t->timestampTz('updated_at')->default(DB::raw('now()'));
        });

        DB::statement('ALTER TABLE workflow_job_progresses ADD CONSTRAINT chk_progress_pct
            CHECK (progress_percent BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_job_progresses');
    }
};
