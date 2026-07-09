<?php

namespace App\Services\Workflow\Admin;

use App\Mail\WorkflowAlertMail;
use App\Models\User;
use App\Models\WorkflowAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 운영 알림 발행/확인 — ADR-0006 · Admin UI Wireframe Spec §13.
 * 미확인 동일 이벤트·동일 대상은 중복 생성하지 않는다(틱 재감지 스팸 방지).
 * 이메일은 HIGH + 이메일 채널 이벤트만, 수신자 미설정 시 발송 생략.
 */
class AlertService
{
    /** 이메일 채널 이벤트 — Wireframe §13 (배너 + 이메일) */
    private const array EMAIL_EVENTS = [
        'REQUIRED_JOB_FAILED', 'STORAGE_IO_ERROR', 'MASTER_RENDITION_MISSING',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function raise(
        string $severity,
        string $eventType,
        string $message,
        ?int $contentId = null,
        ?int $jobId = null,
        ?int $workerId = null,
        ?string $actionLink = null,
    ): ?WorkflowAlert {
        $duplicate = WorkflowAlert::query()
            ->whereNull('acknowledged_at')
            ->where('event_type', $eventType)
            ->where('related_content_id', $contentId)
            ->where('related_job_id', $jobId)
            ->where('related_worker_id', $workerId)
            ->exists();

        if ($duplicate) {
            return null;
        }

        $alert = WorkflowAlert::create([
            'severity' => $severity,
            'event_type' => $eventType,
            'message' => $message,
            'related_content_id' => $contentId,
            'related_job_id' => $jobId,
            'related_worker_id' => $workerId,
            'action_link' => $actionLink,
            'created_at' => now(),
        ]);

        $this->dispatchEmail($alert);

        return $alert;
    }

    /** 멱등 ack — 이미 확인된 알림은 기존 acknowledged_at 그대로 반환, 감사 재기록 없음 (Admin API Spec §21) */
    public function acknowledge(WorkflowAlert $alert, User $actor, ?string $note = null): WorkflowAlert
    {
        if ($alert->acknowledged_at !== null) {
            return $alert;
        }

        return DB::transaction(function () use ($alert, $actor, $note) {
            $alert->forceFill([
                'acknowledged_at' => now(),
                'acknowledged_by' => $actor->id,
                'ack_note' => $note,
            ])->save();

            $this->audit->log('alert.ack', $alert, null, 'ACKNOWLEDGED', ['note' => $note], $note);

            return $alert->refresh();
        });
    }

    private function dispatchEmail(WorkflowAlert $alert): void
    {
        if ($alert->severity !== 'HIGH' || ! in_array($alert->event_type, self::EMAIL_EVENTS, true)) {
            return;
        }

        $recipients = (array) config('workflow.alerts.mail_to', []);

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new WorkflowAlertMail($alert));
        } catch (Throwable $e) {
            // 발송 실패가 Scheduler 틱을 중단시키면 안 된다 — 알림 자체는 영속화됨
            Log::warning('workflow alert mail dispatch failed', [
                'alert_id' => $alert->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
