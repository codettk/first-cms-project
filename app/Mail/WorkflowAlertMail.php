<?php

namespace App\Mail;

use App\Models\WorkflowAlert;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * HIGH 알림 이메일 — ADR-0006 · Admin UI Wireframe Spec §13 (이메일 채널 이벤트).
 */
class WorkflowAlertMail extends Mailable
{
    public function __construct(public readonly WorkflowAlert $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Frame CMS][{$this->alert->severity}] {$this->alert->event_type}",
        );
    }

    public function content(): Content
    {
        $lines = [
            e($this->alert->message),
            '',
            'event_type: '.e($this->alert->event_type),
            'created_at: '.e((string) $this->alert->created_at),
            'action: '.e((string) $this->alert->action_link),
        ];

        return new Content(htmlString: '<pre>'.implode("\n", $lines).'</pre>');
    }
}
