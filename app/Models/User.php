<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use App\Modules\OrderFlow\Data\Models\FlowJobMember;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'initials', 'email', 'email_verified_at', 'phone', 'locale', 'status', 'default_landing_page', 'default_task_view', 'avatar_path', 'notification_preferences', 'is_super_admin', 'password', 'active_session_token', 'active_session_started_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'active_session_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, MustVerifyEmailTrait, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    public function jobMemberships(): HasMany
    {
        return $this->hasMany(FlowJobMember::class);
    }

    public function currentWorkspaceMembership(): ?WorkspaceMembership
    {
        return $this->workspaceMemberships()->with(['workspace', 'role.permissions', 'role.moduleAccess', 'department'])->where('status', 'active')->first();
    }

    public function currentWorkspaceId(): int
    {
        return (int) $this->currentWorkspaceMembership()?->workspace_id;
    }

    public function isFlowSuperAdmin(): bool
    {
        return app(FlowAuthorizationService::class)->isSuperAdmin($this);
    }

    public function isFlowAdministrator(): bool
    {
        return app(FlowAuthorizationService::class)->isAdministrator($this);
    }

    public function hasFlowAction(string $module, string $action, ?\Illuminate\Database\Eloquent\Model $record = null): bool
    {
        return app(FlowAuthorizationService::class)->allows($this, $module, $action, $record);
    }

    public function hasFlowPermission(string $permission): bool
    {
        if ($this->isFlowAdministrator()) {
            return true;
        }

        $membership = $this->currentWorkspaceMembership();

        return (bool) $membership?->role?->permissions->contains('slug', $permission);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'notification_preferences' => 'array',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'active_session_started_at' => 'datetime',
        ];
    }
}
