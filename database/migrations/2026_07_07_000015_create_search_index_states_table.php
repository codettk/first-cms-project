<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_index_states', function (Blueprint $t) {
            $t->foreignId('content_id')->primary()->constrained('contents');
            $t->string('index_doc_id', 100)->nullable();
            $t->unsignedBigInteger('index_version')->default(0);
            $t->string('status', 20)->default('PENDING');
            $t->timestampTz('indexed_at')->nullable();
            $t->text('last_error')->nullable();
        });

        DB::statement("ALTER TABLE search_index_states ADD CONSTRAINT chk_index_status
            CHECK (status IN ('PENDING','INDEXED','STALE','FAILED'))");
        DB::statement("CREATE INDEX idx_index_states_dirty ON search_index_states (status)
            WHERE status IN ('PENDING','STALE','FAILED')");
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index_states');
    }
};
