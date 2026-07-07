<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // POST/PATCH/DELETE 조작 API만 저장 대상 — GET은 기록하지 않는다 (Admin API Spec §22)
        Schema::create('admin_audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_user_id');
            $t->string('action', 100);
            $t->string('target_type', 30);
            $t->unsignedBigInteger('target_id');
            $t->string('before_state', 30)->nullable();
            $t->string('after_state', 30)->nullable();
            $t->jsonb('request_payload')->nullable();
            $t->text('reason')->nullable();
            $t->ipAddress('ip_address');
            $t->string('user_agent', 500)->nullable();
            $t->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::statement('CREATE INDEX idx_audit_actor ON admin_audit_logs (actor_user_id, created_at)');
        DB::statement('CREATE INDEX idx_audit_target ON admin_audit_logs (target_type, target_id)');
        DB::statement('CREATE INDEX idx_audit_action ON admin_audit_logs (action, created_at)');
        DB::statement('CREATE INDEX idx_audit_created ON admin_audit_logs (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
