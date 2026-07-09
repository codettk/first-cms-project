<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 복합 PK (job_id, depends_on_job_id) — Eloquent 단일 PK 전제와 맞지 않으므로
 * 조회 전용으로만 사용하고 쓰기는 query builder 또는 WorkflowJob 관계를 경유한다.
 */
class WorkflowJobDependency extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkflowJob::class, 'job_id');
    }

    public function dependsOnJob(): BelongsTo
    {
        return $this->belongsTo(WorkflowJob::class, 'depends_on_job_id');
    }
}
