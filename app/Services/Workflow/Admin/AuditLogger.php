<?php

namespace App\Services\Workflow\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * admin_audit_logs 기록 표준화 — Controller Service Spec §11.
 * 호출부(CommandService) 트랜잭션에 참여한다 — INSERT 실패 시 조작 전체 롤백.
 * GET 요청은 저장 대상이 아니다 — audit.write 미들웨어 미통과 시 차단.
 */
final class AuditLogger
{
    /** reason 필수 액션 — FormRequest와 이중 검증 */
    private const array REASON_REQUIRED = [
        'content.reprocess', 'content.cancel', 'job.cancel',
        'job.release_lock', 'worker.disable', 'worker.clear_error', 'content.master_download',
    ];

    public function __construct(private readonly AuditContext $ctx) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function log(string $action, Model $target, ?string $before, ?string $after, array $payload, ?string $reason): void
    {
        if (! $this->ctx->isWritable()) {
            throw new LogicException(
                "audit write attempted outside audit.write scope (action: {$action}) — GET은 감사 저장 금지"
            );
        }

        if (in_array($action, self::REASON_REQUIRED, true) && blank($reason)) {
            throw ValidationException::withMessages(['reason' => '사유 입력이 필요합니다.']);
        }

        DB::table('admin_audit_logs')->insert([
            'actor_user_id' => $this->ctx->userId(),
            'action' => $action,
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'before_state' => $before,
            'after_state' => $after,
            'request_payload' => json_encode($payload),
            'reason' => $reason,
            'ip_address' => $this->ctx->ip(),
            'user_agent' => $this->ctx->userAgent(),
            'created_at' => now(),
        ]);
    }
}
