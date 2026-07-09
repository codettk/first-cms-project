<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 관리자 조작 감사 로그 — append-only. UPDATE/DELETE는 DB trigger가 차단한다.
 * GET 요청은 저장 대상이 아니다 (Admin API Spec §22).
 */
class AdminAuditLog extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
