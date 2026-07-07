<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowJobLock extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'job_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'locked_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkflowJob::class, 'job_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(WorkflowWorkerAgent::class, 'locked_by_worker_id');
    }
}
