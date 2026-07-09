<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_renditions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('content_id')->constrained('contents');
            $t->foreignId('media_file_id')->constrained('media_files');
            $t->string('rendition_type', 30);
            $t->string('storage_zone', 30);
            $t->string('variant_key', 50)->default('default');
            $t->string('path', 1000);
            $t->unsignedBigInteger('file_size')->nullable();
            $t->string('checksum', 64)->nullable();
            $t->string('mime_type', 100)->nullable();
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->unsignedBigInteger('duration_ms')->nullable();
            $t->integer('page_no')->nullable();
            $t->unsignedBigInteger('timecode_ms')->nullable();
            $t->foreignId('profile_id')->nullable()->constrained('transcode_profiles');
            $t->jsonb('metadata')->nullable();
            $t->timestampTz('created_at')->default(DB::raw('now()'));
        });

        DB::statement("ALTER TABLE media_renditions ADD CONSTRAINT chk_renditions_type
            CHECK (rendition_type IN ('MASTER','PROXY_VIDEO','PROXY_IMAGE','PROXY_AUDIO','THUMBNAIL','CATALOG',
                'PAGE_PREVIEW','DOCUMENT_PREVIEW','WAVEFORM','OCR_TEXT','EXTRACTED_TEXT'))");
        DB::statement("ALTER TABLE media_renditions ADD CONSTRAINT chk_renditions_zone
            CHECK (storage_zone IN ('TEMP','MASTER','PROXY','THUMBNAIL','CATALOG','DOCUMENT','ARCHIVE'))");

        DB::statement('CREATE INDEX idx_rend_lookup ON media_renditions (content_id, rendition_type)');
        DB::statement('CREATE UNIQUE INDEX uq_rend_path ON media_renditions (storage_zone, path)');
        DB::statement('CREATE INDEX idx_rend_checksum ON media_renditions (checksum)');

        // upsert 키 3종 — variant_key 포함 (Transcode Profile Spec §10 · Migration Seed Spec §7)
        DB::statement('CREATE UNIQUE INDEX uq_rend_single ON media_renditions
            (content_id, rendition_type, variant_key)
            WHERE page_no IS NULL AND timecode_ms IS NULL');
        DB::statement('CREATE UNIQUE INDEX uq_rend_page ON media_renditions
            (content_id, rendition_type, variant_key, page_no) WHERE page_no IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX uq_rend_timecode ON media_renditions
            (content_id, rendition_type, variant_key, timecode_ms) WHERE timecode_ms IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_renditions');
    }
};
