<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 상태 전이 이력 — append-only. UPDATE/DELETE는 DB trigger가 차단한다.
 */
class WorkflowJobHistory extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkflowJob::class, 'job_id');
    }
}
