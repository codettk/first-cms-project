<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 운영 알림 — ADR-0006 (Admin API Spec §21 계약 필드 기반).
 */
class WorkflowAlert extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /** 계약(WorkflowAlertItem) 응답 형태 — Admin API Spec §21 */
    public function toItem(): array
    {
        return [
            'alert_id' => (int) $this->id,
            'severity' => $this->severity,
            'event_type' => $this->event_type,
            'message' => $this->message,
            'related_content_id' => $this->related_content_id !== null ? (int) $this->related_content_id : null,
            'related_job_id' => $this->related_job_id !== null ? (int) $this->related_job_id : null,
            'related_worker_id' => $this->related_worker_id !== null ? (int) $this->related_worker_id : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $this->acknowledged_by !== null ? (int) $this->acknowledged_by : null,
            'action_link' => (string) $this->action_link,
        ];
    }
}
