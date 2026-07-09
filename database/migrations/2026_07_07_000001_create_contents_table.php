<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $t->string('title', 500);
            $t->text('description')->nullable();
            $t->string('media_type', 10)->nullable();
            $t->string('status', 20)->default('UPLOADING');
            $t->string('workflow_version', 20)->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestampsTz();
            $t->softDeletesTz();
        });

        DB::statement("ALTER TABLE contents ADD CONSTRAINT chk_contents_status
            CHECK (status IN ('UPLOADING','REGISTERED','PROCESSING','READY','FAILED','ARCHIVED','DELETED'))");
        DB::statement("ALTER TABLE contents ADD CONSTRAINT chk_contents_media_type
            CHECK (media_type IS NULL OR media_type IN ('VIDEO','IMAGE','AUDIO','DOC'))");
        DB::statement('CREATE INDEX idx_contents_status ON contents (status, created_at DESC)');
        DB::statement('CREATE INDEX idx_contents_type_status ON contents (media_type, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
