<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RUNNING job 진행률 — job_id PK upsert 전용, 종결 시 행 삭제 (Queue Spec §10).
 */
class WorkflowJobProgress extends Model
{
    public const null CREATED_AT = null;

    protected $table = 'workflow_job_progresses';

    public $incrementing = false;

    protected $primaryKey = 'job_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'decimal:2',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkflowJob::class, 'job_id');
    }
}
