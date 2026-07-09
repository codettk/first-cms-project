<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 재색인 단독 INDEX job — SM Spec §14 "기존 인스턴스와 무관한 단독 job" (ADR-0007).
 * FK·UNIQUE(instance_id, job_type)는 유지 — NULL 행은 unique 적용 대상이 아니다.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE workflow_jobs ALTER COLUMN instance_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workflow_jobs ALTER COLUMN instance_id SET NOT NULL');
    }
};
