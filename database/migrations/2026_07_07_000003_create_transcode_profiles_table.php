<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcode_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name', 200);
            $t->string('media_type', 10);
            $t->string('rendition_type', 30);
            $t->string('variant_key', 50);
            $t->string('output_format', 20);
            $t->string('codec', 30)->nullable();
            $t->integer('width')->nullable();
            $t->integer('height')->nullable();
            $t->integer('bitrate')->nullable();
            $t->integer('audio_bitrate')->nullable();
            $t->decimal('frame_rate', 6, 3)->nullable();
            $t->integer('quality')->nullable();
            $t->jsonb('params')->nullable();
            $t->boolean('is_active')->default(true);
        });

        // COMMON은 transcode_profiles.media_type 전용 — contents·OpenAPI MediaType에는 사용하지 않는다
        DB::statement("ALTER TABLE transcode_profiles ADD CONSTRAINT chk_profiles_media_type
            CHECK (media_type IN ('VIDEO','IMAGE','AUDIO','DOC','COMMON'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('transcode_profiles');
    }
};
