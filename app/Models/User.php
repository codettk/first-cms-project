<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'permissions', 'direct_permissions'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'direct_permissions' => 'array',
        ];
    }

    /** 일반 권한 판정 — ADR-0004 브리지 */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? [], true)
            || $this->hasDirectPermission($permission);
    }

    /** HIGH 권한은 개별 부여(direct)만 인정 — 역할 상속 금지 (Controller Service Spec §14) */
    public function hasDirectPermission(string $permission): bool
    {
        return in_array($permission, $this->direct_permissions ?? [], true);
    }
}
