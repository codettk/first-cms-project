<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_job_dependencies', function (Blueprint $t) {
            $t->foreignId('job_id')->constrained('workflow_jobs');
            $t->foreignId('depends_on_job_id')->constrained('workflow_jobs');
            $t->primary(['job_id', 'depends_on_job_id']);
        });

        DB::statement('ALTER TABLE workflow_job_dependencies ADD CONSTRAINT chk_dep_no_self
            CHECK (job_id <> depends_on_job_id)');
        DB::statement('CREATE INDEX idx_dep_reverse ON workflow_job_dependencies (depends_on_job_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_job_dependencies');
    }
};
