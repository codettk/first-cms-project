<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 운영 알림 영속화 — ADR-0006 (스키마는 Admin API Spec §21 계약 필드에서 도출).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('severity', 10);
            $t->string('event_type', 50);
            $t->text('message');
            $t->foreignId('related_content_id')->nullable()->constrained('contents');
            $t->foreignId('related_job_id')->nullable()->constrained('workflow_jobs');
            $t->foreignId('related_worker_id')->nullable()->constrained('workflow_worker_agents');
            $t->string('action_link', 500)->nullable();
            $t->timestampTz('created_at')->default(DB::raw('now()'));
            $t->timestampTz('acknowledged_at')->nullable();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users');
            $t->text('ack_note')->nullable();
        });

        DB::statement("ALTER TABLE workflow_alerts ADD CONSTRAINT chk_alert_severity
            CHECK (severity IN ('HIGH','MED','LOW'))");
        DB::statement('CREATE INDEX idx_alerts_open ON workflow_alerts (created_at)
            WHERE acknowledged_at IS NULL');
        DB::statement('CREATE INDEX idx_alerts_event ON workflow_alerts (event_type, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_alerts');
    }
};
