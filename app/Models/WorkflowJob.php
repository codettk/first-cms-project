<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\JobType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkflowJob extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'job_type' => JobType::class,
            'status' => JobStatus::class,
            'is_required' => 'bool',
            'payload' => 'array',
            'result' => 'array',
            'next_attempt_at' => 'immutable_datetime',
            'cancel_requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(WorkflowWorkerAgent::class, 'worker_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(WorkflowJobHistory::class, 'job_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowJobLog::class, 'job_id');
    }

    public function lock(): HasOne
    {
        return $this->hasOne(WorkflowJobLock::class, 'job_id');
    }

    public function progress(): HasOne
    {
        return $this->hasOne(WorkflowJobProgress::class, 'job_id');
    }

    /** 이 job이 의존하는 선행 job 목록 */
    public function dependsOn(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'workflow_job_dependencies',
            'job_id',
            'depends_on_job_id'
        );
    }

    /** 이 job을 선행으로 참조하는 후행 job 목록 */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'workflow_job_dependencies',
            'depends_on_job_id',
            'job_id'
        );
    }
}
