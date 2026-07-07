<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 관리자 권한 브리지 — ADR-0004.
 * HIGH 권한은 direct_permissions(개별 부여)으로만 인정한다 (Controller Service Spec §14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->jsonb('permissions')->default('[]');
            $t->jsonb('direct_permissions')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['permissions', 'direct_permissions']);
        });
    }
};
