<?php

namespace App\Models;

use App\Enums\WorkerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowWorkerAgent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => WorkerStatus::class,
            'supported_job_types' => 'array',
            'gpu_available' => 'bool',
            'last_heartbeat_at' => 'immutable_datetime',
        ];
    }

    public function currentJob(): BelongsTo
    {
        return $this->belongsTo(WorkflowJob::class, 'current_job_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(WorkflowJob::class, 'worker_id');
    }

    public function locks(): HasMany
    {
        return $this->hasMany(WorkflowJobLock::class, 'locked_by_worker_id');
    }
}
