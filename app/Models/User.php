<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'role_id', 'department_id', 'name', 'email', 'password',
        'is_super_admin', 'is_active', 'locale', 'profile_image_path',
        'wechat_id', 'phone', 'account_status',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Keep the new pivot compatible with older integrations that still set
        // users.role_id directly. Changing the legacy primary role replaces only
        // the previous primary assignment and preserves any other assigned roles.
        static::created(function (User $user): void {
            if (! $user->role_id || ! Schema::hasTable('user_roles')) return;

            DB::table('user_roles')->insertOrIgnore([[
                'user_id' => (int) $user->id,
                'role_id' => (int) $user->role_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
            $user->unsetRelation('roles');
        });

        static::updated(function (User $user): void {
            if (! $user->wasChanged('role_id') || ! Schema::hasTable('user_roles')) return;

            $oldRoleId = (int) ($user->getOriginal('role_id') ?: 0);
            $newRoleId = (int) ($user->role_id ?: 0);

            if ($oldRoleId) {
                DB::table('user_roles')
                    ->where('user_id', $user->id)
                    ->where('role_id', $oldRoleId)
                    ->delete();
            }

            if ($newRoleId) {
                DB::table('user_roles')->insertOrIgnore([[
                    'user_id' => (int) $user->id,
                    'role_id' => $newRoleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]]);
            }

            $user->unsetRelation('roles');
        });
    }

    /**
     * Legacy/primary role retained for backwards compatibility with existing
     * records, workspace memberships, exports and integrations. Effective
     * authorization is calculated from roles().
     */
    public function role(): BelongsTo { return $this->belongsTo(Role::class); }

    /** All roles assigned to the user. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id')->withTimestamps();
    }

    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function assignedTasks(): HasMany { return $this->hasMany(Task::class, 'assignee_id'); }
    public function workspaceMemberships(): HasMany { return $this->hasMany(WorkspaceMembership::class); }

    /**
     * Return assigned roles, including the legacy primary role as a safety
     * fallback for users created by older integrations before they are synced.
     */
    public function assignedRoles(bool $activeOnly = false): Collection
    {
        if ($this->relationLoaded('roles')) {
            $roles = $this->roles;
        } else {
            $roles = $this->roles()->get();
            $this->setRelation('roles', $roles);
        }

        if ($this->role_id && ! $roles->contains('id', (int) $this->role_id)) {
            $legacy = $this->relationLoaded('role') ? $this->role : $this->role()->first();
            if ($legacy) $roles->push($legacy);
        }

        $roles = $roles->unique('id')->values();

        if ($activeOnly) {
            $roles = $roles->filter(fn (Role $role) => $role->is_active !== false)->values();
        }

        return $roles;
    }

    /** @return list<int> */
    public function assignedRoleIds(bool $activeOnly = false): array
    {
        return $this->assignedRoles($activeOnly)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function hasRoleSlug(string|array $slugs, bool $activeOnly = true): bool
    {
        $slugs = array_map('strval', (array) $slugs);
        return $this->assignedRoles($activeOnly)->contains(fn (Role $role) => in_array($role->slug, $slugs, true));
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin || $this->hasRoleSlug('super-admin');
    }

    public function canAccess(string $permission): bool
    {
        return app(\App\Services\AccessControlService::class)->canPermission($this, $permission);
    }

    public function canModule(string $module, string $action = 'view'): bool
    {
        return app(\App\Services\AccessControlService::class)->can($this, $module, $action);
    }

    public function accessScope(string $module): string
    {
        return app(\App\Services\AccessControlService::class)->scope($this, $module);
    }

    public function profileImageUrl(): ?string
    {
        return app(\App\Services\StoredAssetUrlService::class)
            ->profileImageUrl((int) $this->id, $this->profile_image_path);
    }
}
