<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 상태 전이 허용표 — trigger 참조 (State Machine Spec §17). 값은 seed로 관리.
        Schema::create('workflow_job_allowed_transitions', function (Blueprint $t) {
            $t->string('from_status', 20);
            $t->string('to_status', 20);
            $t->primary(['from_status', 'to_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_job_allowed_transitions');
    }
};
