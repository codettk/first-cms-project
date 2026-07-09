<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_job_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('job_id')->constrained('workflow_jobs');
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->string('actor', 50);
            $t->text('note')->nullable();
            $t->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::statement('CREATE INDEX idx_histories_job ON workflow_job_histories (job_id, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_job_histories');
    }
};
