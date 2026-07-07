<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_template_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('template_id')->constrained('workflow_templates');
            $t->string('job_type', 30);
            $t->integer('step_order');
            $t->boolean('is_required')->default(true);
            $t->integer('max_retry')->default(3);
            $t->integer('timeout_sec')->default(600);
            $t->jsonb('depends_on')->nullable();
            $t->jsonb('config')->nullable();
            $t->unique(['template_id', 'job_type']);
        });

        DB::statement("ALTER TABLE workflow_template_steps ADD CONSTRAINT chk_template_steps_job_type
            CHECK (job_type IN ('TM','VERIFY','MA','TC','IMAGE_TC','AUDIO_TC','CA','DOC_PREVIEW','OCR',
                'TEXT_EXTRACT','INDEX','PUBLISH','CLEANUP','WAVEFORM','STT','AI_ANALYSIS'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_template_steps');
    }
};
