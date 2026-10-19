<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserEditorService
{
    public function canEdit(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        if (! app(AccessControlService::class)->isAdministrator($actor)) {
            return false;
        }

        return WorkspaceMembership::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('user_id', $target->id)
            ->exists();
    }

    public function canManageAccess(User $actor): bool
    {
        return app(AccessControlService::class)->isAdministrator($actor);
    }

    public function create(array $data, User $actor): User
    {
        abort_unless($this->canManageAccess($actor), 403);

        $status = (string) ($data['account_status'] ?? 'active');
        abort_unless(in_array($status, ['active', 'inactive', 'suspended'], true), 422);

        $businessUnit = (string) ($data['business_unit'] ?? 'both');
        abort_unless(in_array($businessUnit, ['iid', 'nep', 'both'], true), 422);

        $user = app(AdminService::class)->createUser([
            'name' => trim((string) $data['name']),
            'position' => $this->nullableString($data['position'] ?? null),
            'email' => trim((string) $data['email']),
            'wechat_id' => $this->nullableString($data['wechat_id'] ?? null),
            'phone' => $this->nullableString($data['phone'] ?? null),
            'role_ids' => array_values($data['role_ids'] ?? []),
            'department_id' => $data['department_id'] ?? null,
            'password' => (string) $data['password'],
        ]);

        DB::transaction(function () use ($user, $status, $businessUnit): void {
            $user->forceFill([
                'account_status' => $status,
                'is_active' => $status === 'active',
            ])->save();

            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $this->workspaceId())
                ->where('user_id', $user->id)
                ->firstOrFail();

            $membership->status = $status;
            if (Schema::hasColumn('workspace_memberships', 'business_unit')) {
                $membership->business_unit = $businessUnit;
            }
            $membership->save();
        });

        $fresh = $user->refresh()->loadMissing(['role', 'roles', 'department']);
        app(DashboardService::class)->forget($fresh);
        app(ShellDataService::class)->forget((int) $fresh->id);
        app(WorkspaceRefreshService::class)->touch('role-access');

        return $fresh;
    }

    public function update(User $target, array $data, User $actor): User
    {
        abort_unless($this->canEdit($actor, $target), 403);

        $canManageAccess = $this->canManageAccess($actor);
        $wasAdministrator = app(AccessControlService::class)->isAdministrator($target);
        $oldStatus = $this->accountStatus($target);
        $passwordChanged = filled($data['password'] ?? null);
        $signOutSessions = (bool) ($data['sign_out_sessions'] ?? true);

        DB::transaction(function () use ($target, $data, $actor, $canManageAccess, $passwordChanged, $signOutSessions, $oldStatus) {
            $changes = [
                'name' => trim((string) $data['name']),
                'email' => trim((string) $data['email']),
                'wechat_id' => $this->nullableString($data['wechat_id'] ?? null),
                'phone' => $this->nullableString($data['phone'] ?? null),
            ];

            if ($canManageAccess) {
                $roleIds = collect($data['role_ids'] ?? [$data['role_id'] ?? null])->filter()->map(fn ($id) => (int) $id)->unique()->values();
                abort_if($roleIds->isEmpty(), 422, 'Select at least one role.');
                $roles = Role::query()->where('workspace_id', $this->workspaceId())->whereIn('id', $roleIds)->get()->keyBy('id');
                abort_unless($roles->count() === $roleIds->count(), 422, 'One or more selected roles are invalid.');
                $selectedRoles = $roleIds->map(fn ($id) => $roles->get($id))->values();
                $primaryRole = $selectedRoles->firstWhere('id', $target->role_id) ?: $selectedRoles->first();

                $departmentId = filled($data['department_id'] ?? null) ? (int) $data['department_id'] : null;
                if ($departmentId) {
                    Department::query()->findOrFail($departmentId);
                }

                $status = (string) ($data['account_status'] ?? 'active');
                abort_unless(in_array($status, ['active', 'inactive', 'suspended'], true), 422);

                if ($target->isSuperAdmin()) {
                    $changes['role_id'] = $target->role_id;
                    $selectedRoles = $target->assignedRoles();
                    $changes['is_active'] = true;
                    $changes['account_status'] = 'active';
                } else {
                    abort_if($target->id === $actor->id && $status !== 'active', 422, 'You cannot deactivate or suspend your own signed-in account.');
                    $changes['role_id'] = $primaryRole->id;
                    $changes['department_id'] = $departmentId;
                    $changes['account_status'] = $status;
                    $changes['is_active'] = $status === 'active';
                }
            }

            if ($passwordChanged) {
                $changes['password'] = Hash::make((string) $data['password']);
            }

            $target->update($changes);
            if ($canManageAccess) {
                $target->roles()->sync($selectedRoles->pluck('id')->all());
            }
            $target->refresh()->loadMissing(['role', 'roles']);

            $membership = WorkspaceMembership::query()->firstOrNew([
                'workspace_id' => $this->workspaceId(),
                'user_id' => $target->id,
            ]);
            $membership->role_id = $target->role_id;
            $membership->department_id = $target->department_id;
            $membership->job_title = $this->nullableString($data['position'] ?? null);
            $membership->status = $this->accountStatus($target);
            $membership->joined_at ??= $target->created_at ?: now();

            if ($canManageAccess && array_key_exists('business_unit', $data)) {
                $businessUnit = (string) $data['business_unit'];
                abort_unless(in_array($businessUnit, ['iid', 'nep', 'both'], true), 422);
                $membership->business_unit = $businessUnit;
            } elseif (! $membership->exists && Schema::hasColumn('workspace_memberships', 'business_unit')) {
                $membership->business_unit = 'both';
            }

            $membership->save();

            if ($passwordChanged && $signOutSessions) {
                $this->invalidateSessions($target, $actor);
            }

            $newStatus = $this->accountStatus($target);
            if ($oldStatus === 'active' && $newStatus !== 'active') {
                $this->invalidateSessions($target, $actor, true);
            }

            Activity::create([
                'subject_type' => User::class,
                'subject_id' => $target->id,
                'user_id' => $actor->id,
                'event' => $canManageAccess ? 'access.user_updated' : 'profile.user_updated',
                'description' => ($actor->id === $target->id ? 'Updated own user profile' : 'Updated user '.$target->name).($passwordChanged ? ' and changed password' : ''),
                'meta' => [
                    'account_status' => $this->accountStatus($target),
                    'password_changed' => $passwordChanged,
                ],
            ]);
        });

        app(AccessControlService::class)->forgetRole((int) ($target->role_id ?: 0));
        $fresh = $target->refresh()->loadMissing(['role', 'roles', 'department']);
        app(DashboardService::class)->forget($fresh);
        app(ShellDataService::class)->forget((int) $fresh->id);
        app(WorkspaceRefreshService::class)->touch('role-access');
        if (! $wasAdministrator && app(AccessControlService::class)->isAdministrator($fresh)) {
            app(NotificationService::class)->backfillAdministratorMentions($fresh);
        }

        return $fresh;
    }

    public function updateProfileImage(User $target, UploadedFile $image, User $actor): User
    {
        abort_unless($this->canEdit($actor, $target), 403);

        return app(ProfileService::class)->updateProfileImage($target, $image);
    }

    public function accountStatus(User $user): string
    {
        $stored = trim((string) ($user->account_status ?? ''));
        if (in_array($stored, ['active', 'inactive', 'suspended'], true)) {
            return $stored;
        }

        return $user->is_active ? 'active' : 'inactive';
    }

    public function businessUnit(User $user): string
    {
        $value = WorkspaceMembership::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('user_id', $user->id)
            ->value('business_unit');

        return in_array($value, ['iid', 'nep', 'both'], true) ? $value : 'both';
    }

    private function invalidateSessions(User $target, User $actor, bool $forceAll = false): void
    {
        if (Schema::hasTable('sessions')) {
            $query = DB::table('sessions')->where('user_id', $target->id);

            if (! $forceAll && $target->id === $actor->id && session()->isStarted()) {
                $query->where('id', '!=', session()->getId());
            }

            $query->delete();
        }

        if ($forceAll || $target->id !== $actor->id) {
            Cache::forget('flowtrack:active-login:'.$target->id);
        }
    }

    private function workspaceId(): int
    {
        return app(SetupContext::class)->workspaceId();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
