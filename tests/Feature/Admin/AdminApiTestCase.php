<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Workflow\Admin\WorkflowPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AdminApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** 일반 권한 전체 보유 관리자 (HIGH 없음) */
    protected function admin(array $extraDirect = []): User
    {
        return User::factory()->create([
            'permissions' => WorkflowPermissionService::PERMISSIONS,
            'direct_permissions' => $extraDirect,
        ]);
    }

    /** HIGH 포함 전체 권한 관리자 */
    protected function superAdmin(): User
    {
        return $this->admin(WorkflowPermissionService::HIGH_PERMISSIONS);
    }

    /** 권한 없는 사용자 */
    protected function viewer(): User
    {
        return User::factory()->create([
            'permissions' => ['workflow.view'],
            'direct_permissions' => [],
        ]);
    }
}
