<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_job_locks', function (Blueprint $t) {
            $t->foreignId('job_id')->primary()->constrained('workflow_jobs');
            $t->foreignId('locked_by_worker_id')->constrained('workflow_worker_agents');
            $t->timestampTz('locked_at')->default(DB::raw('now()'));
            $t->timestampTz('locked_until');
            $t->timestampTz('heartbeat_at')->default(DB::raw('now()'));
        });

        DB::statement('CREATE INDEX idx_locks_expiry ON workflow_job_locks (locked_until)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_job_locks');
    }
};
