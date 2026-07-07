<?php

namespace App\Services\Workflow\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * permission 문자열 → Gate 정의 단일 지점 — Controller Service Spec §14 · ADR-0004.
 * HIGH 권한은 hasDirectPermission으로만 인정한다 (역할 기본 포함 금지).
 */
class WorkflowPermissionService
{
    public const array PERMISSIONS = [
        'workflow.view',
        'workflow.logs.view',
        'workflow.retry',
        'workflow.reprocess',
        'workflow.cancel',
    ];

    /** HIGH 권한 — 개별 부여 + 부여 이력 필수 */
    public const array HIGH_PERMISSIONS = [
        'workflow.release_lock',
        'workflow.worker.manage',
        'workflow.force_publish',
        'workflow.master.download',
    ];

    public static function registerGates(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        foreach (self::HIGH_PERMISSIONS as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasDirectPermission($permission));
        }
    }
}
