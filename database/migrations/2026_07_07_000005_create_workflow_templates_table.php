<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50);
            $t->string('name', 200);
            $t->string('media_type', 10);
            $t->integer('version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestampTz('created_at')->default(DB::raw('now()'));
            $t->unique(['code', 'version']);
        });

        DB::statement("ALTER TABLE workflow_templates ADD CONSTRAINT chk_templates_media_type
            CHECK (media_type IN ('VIDEO','IMAGE','AUDIO','DOC'))");
        // 유형당 활성 템플릿 1건 보장
        DB::statement('CREATE UNIQUE INDEX uq_templates_active ON workflow_templates (media_type) WHERE is_active');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_templates');
    }
};
