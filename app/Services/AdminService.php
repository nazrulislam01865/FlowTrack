<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\MasterRecord;
use App\Models\NotificationRule;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\TaskPack;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\Email\ModuleEmailControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class AdminService
{
    public function summary(): array
    {
        return [
            'users' => User::where('is_active', true)->whereHas('workspaceMemberships', fn ($q) => $q->where('workspace_id', $this->workspaceId())->where('status', 'active'))->count(),
            'roles' => Role::where('workspace_id', $this->workspaceId())->where('is_active', true)->count(),
            'task_packs' => TaskPack::where('is_snapshot', false)->count(),
            'rules' => NotificationRule::where('is_active', true)->count(),
            'access_changes' => Activity::where('event', 'like', 'access.%')->where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }

    public function users()
    {
        return $this->usersQuery()->get();
    }

    public function paginateUsers(int $perPage = 10, string $pageName = 'usersPage', string $search = '')
    {
        return $this->usersQuery($search)->paginate($perPage, ['*'], $pageName);
    }

    private function usersQuery(string $search = '')
    {
        $workspaceId = $this->workspaceId();
        $search = trim($search);

        $query = User::with([
                'role', 'roles', 'department',
                'workspaceMemberships' => fn ($q) => $q
                    ->where('workspace_id', $workspaceId)
                    ->select(['id', 'workspace_id', 'user_id', 'job_title']),
            ])
            ->whereHas('workspaceMemberships', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->withCount(['assignedTasks as open_tasks_count' => fn ($q) => $q->whereNull('completed_at')]);

        // CHANGE 2026-08-24:
        // Search only direct user identity fields. This prevents a name search
        // such as "ina" from returning unrelated users merely because their
        // department is "Finance Department" or their role contains the text.
        // A row is returned only when the user name, email or own position matches.
        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($userQuery) use ($like, $workspaceId): void {
                $userQuery
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('workspaceMemberships', fn ($membershipQuery) => $membershipQuery
                        ->where('workspace_id', $workspaceId)
                        ->where('job_title', 'like', $like));
            });
        }

        return $query->orderBy('name');
    }

    public function roles()
    {
        return Role::query()
            ->where('workspace_id', $this->workspaceId())
            ->select(['id', 'workspace_id', 'name', 'slug', 'code', 'description', 'default_scope', 'is_system', 'is_active'])
            ->withCount(['users', 'primaryUsers', 'memberships'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    public function roleOptions()
    {
        return Role::query()
            ->where('workspace_id', $this->workspaceId())
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'workspace_id', 'name', 'slug', 'code', 'description', 'default_scope', 'is_system', 'is_active']);
    }

    public function notificationRules() { return NotificationRule::orderBy('name')->get(); }
    public function taskPacks() { return TaskPack::where('is_snapshot', false)->with('templates')->get(); }

    public function createUser(array $data): User
    {
        $actor = auth()->user();
        $this->assertAdministrator($actor);
        $roles = $this->resolveRoles($data['role_ids'] ?? [$data['role_id'] ?? null]);
        $primaryRole = $roles->first();
        $position = $this->normalizePosition($data['position'] ?? null);
        unset($data['position'], $data['role_ids']);
        $data['role_id'] = $primaryRole->id;
        $userDefaults = ['password' => Hash::make($data['password']), 'is_active' => true, 'locale' => 'en'];
        if (Schema::hasColumn('users', 'account_status')) $userDefaults['account_status'] = 'active';

        $user = DB::transaction(function () use ($data, $userDefaults, $roles, $position) {
            $user = User::create(array_merge($data, $userDefaults));
            $user->roles()->sync($roles->pluck('id')->all());
            $this->syncMembership($user, ['job_title' => $position]);
            return $user;
        });

        $this->audit($user, 'access.user_created', 'User created with roles '.$roles->pluck('name')->join(', '), $actor);
        $user->load(['role', 'roles']);
        app(NotificationService::class)->backfillAdministratorMentions($user);
        return $user;
    }

    public function updateUser(User $user, array $data, User $actor): User
    {
        $this->assertAdministrator($actor);
        $wasAdministrator = app(AccessControlService::class)->isAdministrator($user);
        $roles = $this->resolveRoles($data['role_ids'] ?? [$data['role_id'] ?? null]);
        $position = $this->normalizePosition($data['position'] ?? null);
        $isActive = (bool) ($data['is_active'] ?? true);

        $currentRoleIds = $user->assignedRoleIds();
        if ($user->isSuperAdmin()) {
            $roles = Role::query()->whereIn('id', $currentRoleIds)->cursor()->collect();
            $isActive = true;
        }
        $primaryRole = $roles->firstWhere('id', $user->role_id) ?: $roles->first();

        $changes = [
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'role_id' => $primaryRole?->id,
            'department_id' => $data['department_id'] ?: null,
            'is_active' => $isActive,
        ];
        if (Schema::hasColumn('users', 'account_status')) $changes['account_status'] = $isActive ? 'active' : 'inactive';
        if (!empty($data['password'])) $changes['password'] = Hash::make($data['password']);

        $passwordChanged = array_key_exists('password', $changes);
        DB::transaction(function () use ($user, $changes, $roles, $position): void {
            $user->update($changes);
            $user->roles()->sync($roles->pluck('id')->all());
            $this->syncMembership($user, ['job_title' => $position]);
        });

        if ($passwordChanged && Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        $this->audit($user, 'access.user_updated', 'Updated user '.$user->name.'; roles: '.$roles->pluck('name')->join(', ').($passwordChanged ? '; password changed' : ''), $actor);
        $fresh = $user->refresh()->load(['role','roles']);
        app(DashboardService::class)->forget($fresh);
        app(ShellDataService::class)->forget((int) $fresh->id);
        foreach ($roles as $role) app(AccessControlService::class)->forgetRole((int) $role->id);
        app(WorkspaceRefreshService::class)->touch('role-access');
        if (! $wasAdministrator && app(AccessControlService::class)->isAdministrator($fresh)) {
            app(NotificationService::class)->backfillAdministratorMentions($fresh);
        }
        return $fresh;
    }

    public function positionFor(User $user): ?string
    {
        return WorkspaceMembership::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('user_id', $user->id)
            ->value('job_title');
    }

    public function deleteUser(User $user, User $actor): void
    {
        $this->assertAdministrator($actor);
        abort_if($user->id === $actor->id, 422, 'You cannot delete your own signed-in account.');
        abort_if($user->isSuperAdmin(), 422, 'Super Admin cannot be deleted.');

        DB::transaction(function () use ($user, $actor) {
            $name = $user->name;
            $userId = $user->id;

            // These compatibility columns intentionally have no foreign key in the supplied
            // SQL-aligned structure, so clear them before deleting the User record.
            if (Schema::hasTable('task_pack_items') && Schema::hasColumn('task_pack_items', 'default_assignee_id')) {
                DB::table('task_pack_items')->where('default_assignee_id', $userId)->update(['default_assignee_id' => null]);
            }
            if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'setup_assignee_id')) {
                DB::table('tasks')->where('setup_assignee_id', $userId)->update(['setup_assignee_id' => null]);
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $userId)->delete();
            }

            // Write the audit entry before deletion; activities.user_id uses nullOnDelete so
            // the record survives while its actor reference is safely cleared if necessary.
            $this->audit($user, 'access.user_deleted', 'Deleted user '.$name, $actor);
            $user->delete();
        });
    }

    public function saveRole(array $data, ?int $id, User $actor): Role
    {
        $this->assertAdministrator($actor);
        $workspaceId = $this->workspaceId();
        $role = $id ? Role::where('workspace_id', $workspaceId)->findOrFail($id) : new Role();
        abort_if($id && in_array($role->slug, ['super-admin','admin','administrator'], true), 422, 'Administrator role identity is locked.');

        $name = trim((string) $data['name']);
        $codeInput = trim((string) ($data['code'] ?? ''));
        $baseSlug = Str::slug($codeInput !== '' ? $codeInput : $name);
        $roleCode = Str::upper(Str::replace('-', '_', Str::slug($codeInput !== '' ? $codeInput : $name)));

        if ($baseSlug === '' || $roleCode === '') {
            throw ValidationException::withMessages(['roleCode' => 'Enter a role name or code using letters or numbers.']);
        }
        if (Str::length($roleCode) > 80) {
            throw ValidationException::withMessages(['roleCode' => 'The generated role code cannot exceed 80 characters. Enter a shorter role code.']);
        }

        if (!$id) {
            if (in_array($baseSlug, ['super-admin', 'admin', 'administrator'], true)) {
                throw ValidationException::withMessages(['roleCode' => 'This role code is reserved for administrators.']);
            }

            // Role slugs have a *global* unique index, although roles belong to a
            // workspace. Show a helpful conflict for an existing local role; use
            // a workspace suffix only when another workspace owns the slug.
            $duplicate = Role::query()->where('workspace_id', $workspaceId)
                ->where(function ($query) use ($baseSlug, $name, $roleCode) {
                    $query->where('slug', $baseSlug)
                        ->orWhere('name', $name)
                        ->orWhere('code', $roleCode);
                })->first();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'roleName' => 'This role already exists in this workspace. Open its Permissions to configure product access instead of creating a duplicate.',
                ]);
            }

            $slug = $baseSlug;
            if (Role::query()->where('slug', $slug)->exists()) {
                $prefix = Str::substr($baseSlug, 0, 240 - Str::length((string) $workspaceId)).'-ws'.$workspaceId;
                $slug = $prefix;
                for ($suffix = 2; Role::query()->where('slug', $slug)->exists(); $suffix++) {
                    $slug = $prefix.'-'.$suffix;
                }
            }
        }

        $role->fill([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'slug' => $id ? $role->slug : $slug,
            'code' => $roleCode,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'default_scope' => in_array(($data['default_scope'] ?? 'assigned_jobs'), ['none','own_records','assigned_jobs','department','all_records'], true)
                ? ($data['default_scope'] ?? 'assigned_jobs')
                : 'assigned_jobs',
            'is_system' => $role->is_system ?? false,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        // A newly created Product Manager can manage the product catalogue and
        // categories immediately. Do not grant supplier prices, finance, users,
        // or unrelated workspace permissions. Existing roles stay unchanged.
        $isProductManager = !$id && (Str::slug($name) === 'product-manager' || $baseSlug === 'product-manager');

        try {
            DB::transaction(function () use ($role, $id, $actor, $isProductManager): void {
                $role->save();
                foreach (AccessControlService::MODULES as $module => $_) {
                    $productModule = $isProductManager && in_array($module, ['catalog_products', 'product_categories'], true);
                    RoleModuleAccess::firstOrCreate(
                        ['role_id' => $role->id, 'module_code' => $module],
                        [
                            'record_scope' => $productModule ? 'all_records' : 'none',
                            'actions' => $productModule ? AccessControlService::supportedActions($module) : [],
                        ],
                    );
                }
                $this->audit($role, $id ? 'access.role_updated' : 'access.role_created', ($id ? 'Updated role ' : 'Created role ').$role->name, $actor);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Handles simultaneous submissions / stale forms without leaking SQL.
            throw ValidationException::withMessages([
                'roleName' => 'A role with this identifier already exists. Review the existing roles and try another name or code.',
            ]);
        }

        app(AccessControlService::class)->forgetRole((int) $role->id);
        app(WorkspaceRefreshService::class)->touch('role-access');
        return $role->refresh();
    }

    /**
     * Permanently delete a role while preserving every affected user account.
     * The deleted role is removed from the multi-role pivot, the legacy primary
     * role and workspace membership. If the user has another role in this
     * workspace, that role becomes the compatibility primary/membership role;
     * otherwise those compatibility references are left null.
     */
    public function deleteRole(Role $role, User $actor): int
    {
        $this->assertAdministrator($actor);
        $this->assertRoleWorkspace($role);

        $workspaceId = $this->workspaceId();
        $roleId = (int) $role->id;
        $roleName = (string) $role->name;

        $affectedUserIds = collect()
            ->merge(Schema::hasTable('user_roles')
                ? DB::table('user_roles')->where('role_id', $roleId)->pluck('user_id')
                : collect())
            ->merge(Schema::hasTable('users') && Schema::hasColumn('users', 'role_id')
                ? DB::table('users')->where('role_id', $roleId)->pluck('id')
                : collect())
            ->merge(Schema::hasTable('workspace_memberships') && Schema::hasColumn('workspace_memberships', 'role_id')
                ? DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->where('role_id', $roleId)->pluck('user_id')
                : collect())
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        DB::transaction(function () use ($role, $actor, $workspaceId, $roleId, $roleName, $affectedUserIds): void {
            // Keep the audit event even though the Role row itself is about to be
            // permanently removed. Activity subjects are intentionally polymorphic
            // IDs without a destructive FK.
            $this->audit(
                $role,
                'access.role_deleted',
                'Permanently deleted role '.$roleName,
                $actor,
                ['affected_users' => $affectedUserIds->count()],
            );

            foreach ($affectedUserIds as $userId) {
                $replacementRoleId = null;

                if (Schema::hasTable('user_roles')) {
                    $replacementRoleId = DB::table('user_roles')
                        ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                        ->where('user_roles.user_id', $userId)
                        ->where('user_roles.role_id', '!=', $roleId)
                        ->where('roles.workspace_id', $workspaceId)
                        ->orderByDesc('roles.is_active')
                        ->orderBy('roles.id')
                        ->value('roles.id');

                    DB::table('user_roles')
                        ->where('user_id', $userId)
                        ->where('role_id', $roleId)
                        ->delete();
                }

                // Older integrations may have a valid legacy primary role that
                // has not yet been mirrored into user_roles. Preserve it rather
                // than blanking the workspace membership unnecessarily.
                if ($replacementRoleId === null && Schema::hasTable('users') && Schema::hasColumn('users', 'role_id')) {
                    $replacementRoleId = DB::table('users')
                        ->join('roles', 'roles.id', '=', 'users.role_id')
                        ->where('users.id', $userId)
                        ->where('users.role_id', '!=', $roleId)
                        ->where('roles.workspace_id', $workspaceId)
                        ->value('roles.id');
                }

                if (Schema::hasTable('users') && Schema::hasColumn('users', 'role_id')) {
                    DB::table('users')
                        ->where('id', $userId)
                        ->where('role_id', $roleId)
                        ->update(['role_id' => $replacementRoleId]);
                }

                if (Schema::hasTable('workspace_memberships') && Schema::hasColumn('workspace_memberships', 'role_id')) {
                    DB::table('workspace_memberships')
                        ->where('workspace_id', $workspaceId)
                        ->where('user_id', $userId)
                        ->where('role_id', $roleId)
                        ->update(['role_id' => $replacementRoleId]);
                }
            }

            // Role does not use SoftDeletes. delete() therefore removes it
            // permanently; module permissions and legacy permission pivots cascade.
            $role->delete();
        });

        $actor->unsetRelation('role');
        $actor->unsetRelation('roles');
        $actor->unsetRelation('workspaceMemberships');
        $actor->refresh();

        app(AccessControlService::class)->forgetRole($roleId);
        app(WorkspaceRefreshService::class)->touch('role-access');

        return $affectedUserIds->count();
    }

    public function toggleMatrixAction(Role $role, string $module, string $action, User $actor): void
    {
        $this->assertAdministrator($actor);
        $this->assertRoleWorkspace($role);
        abort_if(in_array($role->slug, ['super-admin','admin','administrator'], true), 422, 'Administrator permissions are always enabled.');
        abort_unless(isset(AccessControlService::MODULES[$module]) && AccessControlService::supportsAction($module, $action), 422, 'That permission is not supported by this module.');
        $row = RoleModuleAccess::firstOrCreate(['role_id' => $role->id, 'module_code' => $module], ['record_scope' => 'none', 'actions' => []]);
        $this->setMatrixAction($role, $module, $action, !collect($row->actions ?: [])->contains($action), $actor);
    }

    public function setMatrixAction(Role $role, string $module, string $action, bool $enabled, User $actor): void
    {
        $this->assertAdministrator($actor);
        $this->assertRoleWorkspace($role);
        abort_if(in_array($role->slug, ['super-admin','admin','administrator'], true), 422, 'Administrator permissions are always enabled.');
        abort_unless(isset(AccessControlService::MODULES[$module]) && AccessControlService::supportsAction($module, $action), 422, 'That permission is not supported by this module.');

        $row = RoleModuleAccess::firstOrCreate(['role_id' => $role->id, 'module_code' => $module], ['record_scope' => 'none', 'actions' => []]);
        $supported = AccessControlService::supportedActions($module);
        $actions = collect($row->actions ?: [])->filter(fn ($value) => in_array($value, $supported, true));
        if ($actions->contains('manage')) $actions = collect($supported);
        $actions = $enabled
            ? $actions->push($action)->unique()->values()
            : $actions->reject(fn ($x) => $x === $action)->values();

        // Manage is a full-control shortcut. Keep the visual matrix and the
        // effective backend permission identical: enabling Manage checks every
        // supported action; disabling any granular action removes Manage first
        // so that the unchecked action is genuinely revoked.
        if ($action === 'manage') {
            $actions = $enabled ? collect($supported) : collect();
        } elseif (!$enabled && $actions->contains('manage')) {
            $actions = $actions->reject(fn ($value) => $value === 'manage')->values();
        }

        // Action permissions must never create an unusable role. Any record-level
        // action implies View; turning View off removes its dependent actions.
        if (in_array('view', $supported, true)) {
            if ($enabled && $action !== 'view') $actions->push('view');
            if (!$enabled && $action === 'view') $actions = collect();
        }
        $storedActions = $actions->filter(fn ($value) => in_array($value, $supported, true))->unique()->values()->all();

        $recordScope = (string) ($row->record_scope ?: 'none');
        if ($storedActions && $recordScope === 'none') {
            $recordScope = ($role->default_scope && $role->default_scope !== 'none')
                ? $role->default_scope
                : 'assigned_jobs';
        }

        $row->update([
            'actions' => $storedActions,
            'record_scope' => $recordScope,
        ]);

        $this->audit(
            $role,
            'access.permission_changed',
            ($enabled ? 'Granted ' : 'Removed ').$action.' on '.$module.' for '.$role->name,
            $actor,
            compact('module', 'action', 'enabled'),
        );
        app(AccessControlService::class)->forgetRole((int) $role->id);
        app(WorkspaceRefreshService::class)->touch('role-access');
    }

    public function setScope(Role $role, string $module, string $scope, User $actor): void
    {
        $this->assertAdministrator($actor);
        $this->assertRoleWorkspace($role);
        abort_if(in_array($role->slug, ['super-admin','admin','administrator'], true), 422, 'Administrator scope is always all records.');
        abort_unless(isset(AccessControlService::MODULES[$module]) && AccessControlService::supportsScope($module), 422, 'This module does not use record scope.');
        abort_unless(in_array($scope, AccessControlService::RECORD_SCOPES, true), 422, 'Unsupported record scope.');
        $row = RoleModuleAccess::firstOrCreate(['role_id' => $role->id, 'module_code' => $module], ['actions' => [], 'record_scope' => 'none']);
        $row->update([
            'record_scope' => $scope,
            'actions' => $scope === 'none' ? [] : ($row->actions ?: []),
        ]);
        $this->audit($role, 'access.scope_changed', 'Changed '.$module.' scope for '.$role->name.' to '.str_replace('_', ' ', $scope), $actor, compact('module','scope'));
        app(AccessControlService::class)->forgetRole((int) $role->id);
        app(WorkspaceRefreshService::class)->touch('role-access');
    }

    public function assignRole(User $user, Role $role, User $actor): void
    {
        $this->syncUserRoles($user, [$role->id], $actor);
    }

    public function syncUserRoles(User $user, array $roleIds, User $actor): void
    {
        $this->assertAdministrator($actor);
        $wasAdministrator = app(AccessControlService::class)->isAdministrator($user);
        $roles = $this->resolveRoles($roleIds);

        if ($user->isSuperAdmin()) {
            abort_unless($roles->contains(fn (Role $role) => $role->slug === 'super-admin'), 422, 'A Super Admin cannot be downgraded here.');
        }

        $oldNames = $user->assignedRoles()->pluck('name')->all();
        $primaryRole = $roles->firstWhere('id', $user->role_id) ?: $roles->first();

        DB::transaction(function () use ($user, $roles, $primaryRole): void {
            $user->update(['role_id' => $primaryRole->id]);
            $user->roles()->sync($roles->pluck('id')->all());
            $this->syncMembership($user);
        });

        $newNames = $roles->pluck('name')->all();
        $this->audit($user, 'access.roles_assigned', 'Roles changed from '.implode(', ', $oldNames).' to '.implode(', ', $newNames), $actor, ['old' => $oldNames, 'new' => $newNames]);
        $fresh = $user->refresh()->load(['role','roles']);
        app(DashboardService::class)->forget($fresh);
        app(ShellDataService::class)->forget((int) $fresh->id);
        foreach ($roles as $role) app(AccessControlService::class)->forgetRole((int) $role->id);
        app(WorkspaceRefreshService::class)->touch('role-access');
        if (! $wasAdministrator && app(AccessControlService::class)->isAdministrator($fresh)) {
            app(NotificationService::class)->backfillAdministratorMentions($fresh);
        }
    }

    public function toggleUserActive(User $user, User $actor): void
    {
        $this->assertAdministrator($actor);
        abort_if($user->isSuperAdmin(), 422, 'Super Admin cannot be deactivated.');
        $newActive = !$user->is_active;
        $statusChanges = ['is_active' => $newActive];
        if (Schema::hasColumn('users', 'account_status')) $statusChanges['account_status'] = $newActive ? 'active' : 'inactive';
        $user->update($statusChanges);
        $this->syncMembership($user);
        $this->audit($user, 'access.user_status_changed', 'User '.($user->is_active ? 'activated' : 'deactivated'), $actor);
    }

    public function auditLog()
    {
        return Activity::with('user')->where('event', 'like', 'access.%')->latest()->limit(100)->lazy(100)->collect();
    }

    public function securitySettings(): array
    {
        $defaults = [
            'require_mfa_privileged' => ['Require MFA for privileged roles', true],
            'strong_password_policy' => ['Strong password policy', true],
            'automatic_timeout' => ['Automatic timeout', true],
            'restrict_bulk_exports' => ['Restrict bulk exports', true],
            'temporary_access_expiry' => ['Temporary access expiry', true],
            'quarterly_access_review' => ['Quarterly access review', true],
        ];
        $rows = MasterRecord::where('workspace_id', $this->workspaceId())->where('type', 'security_setting')->get()->keyBy('code');
        return collect($defaults)->map(function ($item, $code) use ($rows) {
            $row = $rows->get($code);
            return ['code' => $code, 'label' => $item[0], 'enabled' => $row ? (bool) data_get($row->metadata, 'enabled', true) : $item[1]];
        })->values()->all();
    }

    public function toggleSecurity(string $code, User $actor): void
    {
        $this->assertAdministrator($actor);
        $settings = collect($this->securitySettings())->keyBy('code');
        abort_unless($settings->has($code), 422);
        $current = $settings[$code];
        MasterRecord::updateOrCreate(
            ['workspace_id' => $this->workspaceId(), 'type' => 'security_setting', 'code' => $code],
            ['name' => $current['label'], 'metadata' => ['enabled' => !$current['enabled']], 'status' => 'active', 'sort_order' => 0],
        );
        $this->audit($actor, 'access.security_changed', $current['label'].' '.(!$current['enabled'] ? 'enabled' : 'disabled'), $actor);
    }


    /** @return array<int,array{module:string,code:string,label:string,description:string,enabled:bool}> */
    public function emailServiceSettings(): array
    {
        return app(ModuleEmailControlService::class)->settings();
    }

    public function toggleEmailService(string $module, User $actor): bool
    {
        $this->assertAdministrator($actor);

        return app(ModuleEmailControlService::class)->toggle($module, $actor);
    }

    public function setEmailService(string $module, bool $enabled, User $actor): bool
    {
        $this->assertAdministrator($actor);

        return app(ModuleEmailControlService::class)->setEnabled($module, $enabled, $actor);
    }

    public function toggleRule(int $id): void
    {
        $this->assertAdministrator(auth()->user());
        $r = NotificationRule::findOrFail($id);
        $r->update(['is_active' => !$r->is_active]);
    }


    private function resolveRoles(array $roleIds)
    {
        $ids = collect($roleIds)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        abort_if($ids->isEmpty(), 422, 'Select at least one role.');

        $roles = Role::query()
            ->where('workspace_id', $this->workspaceId())
            ->whereIn('id', $ids)
            ->cursor()
            ->collect()
            ->keyBy('id');

        abort_unless($roles->count() === $ids->count(), 422, 'One or more selected roles are invalid for this workspace.');

        return $ids->map(fn ($id) => $roles->get($id))->values();
    }

    private function workspaceId(): int
    {
        return app(SetupContext::class)->workspaceId();
    }

    private function assertRoleWorkspace(Role $role): void
    {
        abort_unless((int) ($role->workspace_id ?: $this->workspaceId()) === $this->workspaceId(), 404);
    }

    private function assertAdministrator(?User $actor): void
    {
        abort_unless($actor && app(AccessControlService::class)->isAdministrator($actor), 403);
    }

    private function syncMembership(User $user, array $extra = []): void
    {
        if (!$user->role_id) return;
        WorkspaceMembership::updateOrCreate(
            ['workspace_id' => $this->workspaceId(), 'user_id' => $user->id],
            array_merge([
                'role_id' => $user->role_id,
                'department_id' => $user->department_id,
                'status' => in_array((string) ($user->account_status ?? ''), ['active','inactive','suspended'], true) ? $user->account_status : ($user->is_active ? 'active' : 'inactive'),
                'joined_at' => $user->created_at ?: now(),
            ], $extra),
        );
    }

    private function normalizePosition(mixed $position): ?string
    {
        $position = trim((string) $position);

        return $position !== '' ? $position : null;
    }

    private function audit(object $subject, string $event, string $description, ?User $actor = null, array $meta = []): void
    {
        Activity::create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'user_id' => ($actor ?: auth()->user())?->id,
            'event' => $event,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }
}
