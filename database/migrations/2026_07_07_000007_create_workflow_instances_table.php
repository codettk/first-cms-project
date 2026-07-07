<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_id')->constrained('contents');
            $t->foreignId('template_id')->constrained('workflow_templates');
            $t->string('status', 20)->default('RUNNING');
            $t->timestampTz('started_at')->default(DB::raw('now()'));
            $t->timestampTz('finished_at')->nullable();
        });

        DB::statement("ALTER TABLE workflow_instances ADD CONSTRAINT chk_wf_instances_status
            CHECK (status IN ('RUNNING','SUCCESS','FAILED','CANCELED'))");
        // 콘텐츠당 RUNNING 인스턴스 1건 (핵심 무결성)
        DB::statement("CREATE UNIQUE INDEX uq_instances_running ON workflow_instances (content_id)
            WHERE status = 'RUNNING'");
        DB::statement('CREATE INDEX idx_instances_content ON workflow_instances (content_id, started_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};
