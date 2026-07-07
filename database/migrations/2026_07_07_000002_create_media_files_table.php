<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_id')->constrained('contents');
            $t->string('original_filename', 500);
            $t->string('ext', 20);
            $t->string('detected_mime', 100)->nullable();
            $t->unsignedBigInteger('file_size');
            $t->string('checksum', 64);
            $t->string('temp_path', 1000)->nullable();
            $t->jsonb('media_info')->nullable();
            $t->timestampsTz();
        });

        DB::statement('ALTER TABLE media_files ADD CONSTRAINT chk_media_files_size CHECK (file_size > 0)');
        DB::statement('CREATE INDEX idx_media_files_content ON media_files (content_id)');
        DB::statement('CREATE INDEX idx_media_files_checksum ON media_files (checksum)');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
