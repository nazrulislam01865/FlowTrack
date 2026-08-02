<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\Permission;
use App\Modules\OrderFlow\Data\Models\Role;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleMatrixService
{
    public function __construct(private readonly RoleMatrixCatalog $catalog)
    {
    }

    public function accessPayload(int $workspaceId): array
    {
        $roles = Role::query()
            ->where('workspace_id', $workspaceId)
            ->withCount(['memberships as member_count' => fn ($query) => $query->where('status', 'active')])
            ->with(['moduleAccess', 'permissions:id,slug'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        foreach ($roles as $role) {
            $this->ensureInitialized($role);
        }

        $roles->load('moduleAccess');

        $members = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->with(['user:id,name,initials,email,status,is_super_admin,locale', 'role:id,name,code', 'department:id,name'])
            ->orderBy('id')
            ->get()
            ->map(fn (WorkspaceMembership $membership) => [
                'id' => $membership->id,
                'userId' => $membership->user_id,
                'name' => $membership->user?->name ?? 'Unknown user',
                'initials' => $membership->user?->initials,
                'email' => $membership->user?->email,
                'departmentId' => $membership->department_id,
                'department' => $membership->department?->name ?? 'Unassigned',
                'roleId' => $membership->role_id,
                'roleName' => $membership->role?->name ?? 'Unassigned',
                'roleCode' => $membership->role?->code,
                'jobTitle' => $membership->job_title,
                'status' => $membership->status,
                'locale' => $membership->user?->locale ?: 'en',
                'isSuperAdmin' => (bool) $membership->user?->is_super_admin,
            ])
            ->values()
            ->all();

        return [
            'catalog' => $this->catalog->payload(),
            'roles' => $roles->map(fn (Role $role) => $this->mapRole($role))->values()->all(),
            'members' => $members,
        ];
    }

    public function createRole(int $workspaceId, array $data): Role
    {
        return DB::transaction(function () use ($workspaceId, $data): Role {
            $code = $this->normalizeCode($data['code']);
            $role = Role::create([
                'workspace_id' => $workspaceId,
                'name' => $data['name'],
                'slug' => Str::of($code)->lower()->replace('_', '-')->toString(),
                'code' => $code,
                'description' => $data['description'] ?? null,
                'default_scope' => $data['default_scope'],
                'is_system' => false,
                'is_active' => $data['is_active'] ?? true,
                'sensitive_fields' => [],
            ]);

            if ($this->isAdministratorRole($role)) {
                $this->writeMatrix($role, $this->fullMatrix());
                $role->update([
                    'default_scope' => 'all_records',
                    'is_active' => true,
                    'sensitive_fields' => $this->catalog->sensitiveFieldCodes(),
                ]);
            } elseif (! empty($data['copy_from_role_id'])) {
                $source = Role::query()
                    ->where('workspace_id', $workspaceId)
                    ->with('moduleAccess')
                    ->findOrFail($data['copy_from_role_id']);
                $this->ensureInitialized($source);
                foreach ($source->moduleAccess as $access) {
                    $role->moduleAccess()->create([
                        'module_code' => $access->module_code,
                        'record_scope' => $access->record_scope,
                        'actions' => $access->actions,
                    ]);
                }
                $role->update(['sensitive_fields' => $source->sensitive_fields ?: []]);
            } else {
                $this->writeMatrix($role, $this->emptyMatrix($data['default_scope']));
            }

            $this->syncLegacyPermissions($role->fresh('moduleAccess'));

            return $role->fresh(['moduleAccess'])->loadCount(['memberships as member_count' => fn ($query) => $query->where('status', 'active')]);
        });
    }

    public function updateRole(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data): Role {
            $code = $this->normalizeCode($data['code']);
            $administratorRole = in_array($code, config('flowtrack.administrator_role_codes', []), true);
            $role->update([
                'name' => $data['name'],
                'slug' => Str::of($code)->lower()->replace('_', '-')->toString(),
                'code' => $code,
                'description' => $data['description'] ?? null,
                'default_scope' => $administratorRole ? 'all_records' : $data['default_scope'],
                'is_active' => $administratorRole ? true : $data['is_active'],
                'sensitive_fields' => $administratorRole ? $this->catalog->sensitiveFieldCodes() : $role->sensitive_fields,
            ]);

            if ($administratorRole) {
                $this->writeMatrix($role, $this->fullMatrix());
                $this->syncLegacyPermissions($role->fresh('moduleAccess'));
            }

            return $role->fresh(['moduleAccess'])->loadCount(['memberships as member_count' => fn ($query) => $query->where('status', 'active')]);
        });
    }

    public function updateMatrix(Role $role, array $modules, array $sensitiveFields, User $actor): Role
    {
        $normalized = $this->normalizeMatrix($modules, $role->default_scope);
        if ($this->isAdministratorRole($role)) {
            $normalized = $this->fullMatrix();
            $sensitiveFields = $this->catalog->sensitiveFieldCodes();
        }
        $actorRoleId = $actor->currentWorkspaceMembership()?->role_id;

        if ($actorRoleId === $role->id && ! $this->grantsAdministration($normalized)) {
            throw ValidationException::withMessages([
                'modules' => 'You cannot remove Users & Access management from your own active role.',
            ]);
        }

        if ($this->isAdministratorRole($role) && ! $this->grantsAdministration($normalized)) {
            throw ValidationException::withMessages([
                'modules' => 'Administrator roles always retain unrestricted system access.',
            ]);
        }

        $allowedFields = array_values(array_intersect($sensitiveFields, $this->catalog->sensitiveFieldCodes()));

        DB::transaction(function () use ($role, $normalized, $allowedFields): void {
            $this->writeMatrix($role, $normalized);
            $role->update(['sensitive_fields' => $allowedFields]);
            $this->syncLegacyPermissions($role->fresh('moduleAccess'));
        });

        return $role->fresh(['moduleAccess'])->loadCount(['memberships as member_count' => fn ($query) => $query->where('status', 'active')]);
    }

    public function mapRole(Role $role): array
    {
        $matrix = [];
        foreach ($this->catalog->moduleCodes() as $moduleCode) {
            $access = $role->moduleAccess->firstWhere('module_code', $moduleCode);
            $matrix[$moduleCode] = [
                'scope' => $access?->record_scope ?? 'none',
                'actions' => array_values(array_intersect($access?->actions ?? [], $this->catalog->actionCodes())),
            ];
        }

        return [
            'id' => $role->id,
            'name' => $role->name,
            'code' => $role->code ?: Str::of($role->slug)->upper()->replace('-', '_')->toString(),
            'slug' => $role->slug,
            'description' => $role->description ?? '',
            'defaultScope' => $role->default_scope ?: 'assigned_jobs',
            'system' => (bool) $role->is_system,
            'active' => (bool) $role->is_active,
            'members' => (int) ($role->member_count ?? 0),
            'sensitiveFields' => array_values(array_intersect($role->sensitive_fields ?: [], $this->catalog->sensitiveFieldCodes())),
            'matrix' => $matrix,
        ];
    }

    public function ensureInitialized(Role $role): void
    {
        if ($this->isAdministratorRole($role)) {
            if ($this->administratorMatrixIsCurrent($role)) {
                return;
            }

            DB::transaction(function () use ($role): void {
                $this->writeMatrix($role, $this->fullMatrix());
                $role->update([
                    'default_scope' => 'all_records',
                    'is_active' => true,
                    'sensitive_fields' => $this->catalog->sensitiveFieldCodes(),
                ]);
                $this->syncLegacyPermissions($role->fresh('moduleAccess'));
            });
            $role->load('moduleAccess');
            return;
        }

        if ($role->moduleAccess->isNotEmpty()) {
            $this->ensureMatrixContainsCatalogModules($role);
            return;
        }

        DB::transaction(function () use ($role): void {
            $profile = $this->profile($role);
            $recommendedScope = match ($profile) {
                'admin', 'management', 'auditor' => 'all_records',
                'sales', 'accounts' => 'selected_clients',
                default => 'assigned_jobs',
            };
            if ($role->default_scope !== $recommendedScope && in_array($profile, ['admin', 'management', 'sales', 'accounts', 'auditor'], true)) {
                $role->update(['default_scope' => $recommendedScope]);
                $role->default_scope = $recommendedScope;
            }

            $matrix = $this->defaultMatrix($role);
            $this->writeMatrix($role, $matrix);
            if (empty($role->sensitive_fields)) {
                $role->update(['sensitive_fields' => $this->defaultSensitiveFields($role)]);
            }
        });

        $role->load('moduleAccess');
    }

    private function ensureMatrixContainsCatalogModules(Role $role): void
    {
        $existing = $role->moduleAccess->pluck('module_code')->map(fn ($code): string => (string) $code)->all();
        $missing = array_values(array_diff($this->catalog->moduleCodes(), $existing));

        if ($missing === []) {
            return;
        }

        DB::transaction(function () use ($role, $missing): void {
            foreach ($missing as $moduleCode) {
                $role->moduleAccess()->updateOrCreate(
                    ['module_code' => $moduleCode],
                    ['record_scope' => $role->default_scope ?: 'assigned_jobs', 'actions' => []],
                );
            }
            $this->syncLegacyPermissions($role->fresh('moduleAccess'));
        });

        $role->load('moduleAccess');
    }

    private function administratorMatrixIsCurrent(Role $role): bool
    {
        $moduleAccess = $role->moduleAccess;
        if ($role->default_scope !== 'all_records' || ! $role->is_active) {
            return false;
        }

        if (! $this->sameStringSet($role->sensitive_fields ?: [], $this->catalog->sensitiveFieldCodes())) {
            return false;
        }

        foreach ($this->catalog->moduleCodes() as $moduleCode) {
            $access = $moduleAccess->firstWhere('module_code', $moduleCode);
            if (! $access || $access->record_scope !== 'all_records') {
                return false;
            }
            if (! $this->sameStringSet($access->actions ?: [], $this->catalog->actionCodes())) {
                return false;
            }
        }

        return true;
    }

    private function sameStringSet(array $left, array $right): bool
    {
        $left = array_values(array_unique(array_map('strval', $left)));
        $right = array_values(array_unique(array_map('strval', $right)));
        sort($left);
        sort($right);

        return $left === $right;
    }

    private function normalizeCode(string $code): string
    {
        return Str::of($code)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '_')
            ->trim('_')
            ->substr(0, 80)
            ->value();
    }

    private function normalizeMatrix(array $modules, string $defaultScope): array
    {
        $input = collect($modules)->keyBy('module_code');
        $matrix = [];
        foreach ($this->catalog->moduleCodes() as $moduleCode) {
            $row = $input->get($moduleCode, []);
            $scope = in_array($row['scope'] ?? null, $this->catalog->scopeCodes(), true)
                ? $row['scope']
                : $defaultScope;
            $actions = array_values(array_unique(array_intersect($row['actions'] ?? [], $this->catalog->actionCodes())));
            $matrix[$moduleCode] = compact('scope', 'actions');
        }

        return $matrix;
    }

    private function emptyMatrix(string $scope = 'assigned_jobs'): array
    {
        return collect($this->catalog->moduleCodes())
            ->mapWithKeys(fn (string $code) => [$code => ['scope' => $scope, 'actions' => []]])
            ->all();
    }

    private function defaultMatrix(Role $role): array
    {
        $scope = $role->default_scope ?: 'assigned_jobs';
        $matrix = $this->emptyMatrix($scope);
        $profile = $this->profile($role);

        $allow = function (array|string $modules, array $actions, ?string $rowScope = null) use (&$matrix, $scope): void {
            foreach (Arr::wrap($modules) as $module) {
                if (! isset($matrix[$module])) {
                    continue;
                }
                $matrix[$module] = [
                    'scope' => $rowScope ?: ($matrix[$module]['scope'] ?? $scope),
                    'actions' => array_values(array_unique(array_merge($matrix[$module]['actions'] ?? [], $actions))),
                ];
            }
        };

        if ($profile === 'admin') {
            $allow($this->catalog->moduleCodes(), $this->catalog->actionCodes(), 'all_records');
            return $matrix;
        }

        if ($profile === 'management') {
            $allow(['dashboard', 'clients', 'jobs', 'tasks', 'quotation', 'artwork', 'sample', 'production', 'shipment', 'invoice', 'documents', 'reports', 'audit'], ['view', 'create', 'link', 'edit_own', 'edit_all', 'assign', 'export', 'override'], 'all_records');
            $allow(['workflow', 'masterdata'], ['view', 'create', 'edit_own', 'edit_all', 'delete', 'manage'], 'all_records');
            $allow('users', ['view', 'create', 'edit_all', 'assign', 'manage'], 'all_records');
            return $matrix;
        }

        $templates = [
            'jobmanager' => [
                [['dashboard', 'clients', 'jobs', 'tasks', 'quotation', 'artwork', 'sample', 'production', 'shipment', 'documents', 'reports'], ['view', 'create', 'edit_own', 'edit_all', 'assign', 'export', 'override'], 'assigned_jobs'],
            ],
            'sales' => [
                [['dashboard', 'clients', 'jobs', 'tasks', 'quotation', 'documents'], ['view', 'create', 'edit_own', 'assign', 'export'], 'selected_clients'],
            ],
            'designer' => [
                [['dashboard', 'jobs', 'tasks', 'artwork', 'documents'], ['view', 'create', 'edit_own', 'export'], 'assigned_jobs'],
            ],
            'sourcing' => [
                [['dashboard', 'jobs', 'tasks', 'quotation', 'sample', 'production', 'documents'], ['view', 'create', 'edit_own', 'export'], 'assigned_jobs'],
            ],
            'production' => [
                [['dashboard', 'jobs', 'tasks', 'production', 'documents'], ['view', 'create', 'edit_own'], 'assigned_jobs'],
            ],
            'shipment' => [
                [['dashboard', 'jobs', 'tasks', 'shipment', 'documents'], ['view', 'create', 'edit_own', 'export'], 'assigned_jobs'],
            ],
            'accounts' => [
                [['dashboard', 'clients', 'jobs', 'tasks', 'invoice', 'documents', 'reports'], ['view', 'create', 'edit_own', 'edit_all', 'export'], 'selected_clients'],
            ],
            'auditor' => [
                [['dashboard', 'clients', 'jobs', 'tasks', 'quotation', 'artwork', 'sample', 'production', 'shipment', 'invoice', 'documents', 'reports', 'audit'], ['view', 'export'], 'all_records'],
            ],
            'document_linker' => [
                [['dashboard', 'clients', 'jobs', 'tasks'], ['view'], 'assigned_jobs'],
                [['documents'], ['view', 'create', 'link', 'edit_own', 'export'], 'assigned_jobs'],
            ],
            'member' => [
                [['dashboard', 'jobs', 'tasks', 'documents'], ['view', 'edit_own'], 'assigned_jobs'],
            ],
        ];

        foreach ($templates[$profile] ?? $templates['member'] as [$modules, $actions, $rowScope]) {
            $allow($modules, $actions, $rowScope);
        }

        if (in_array('create', $matrix['documents']['actions'] ?? [], true)) {
            $allow('documents', ['link'], $matrix['documents']['scope'] ?? $scope);
        }

        // Preserve every existing Laravel permission when translating a legacy role into the matrix.
        $legacy = $role->permissions->pluck('slug')->all();
        if (in_array('dashboard.view', $legacy, true)) $allow('dashboard', ['view'], $scope);
        if (in_array('jobs.view-assigned', $legacy, true)) $allow('jobs', ['view'], 'assigned_jobs');
        if (in_array('jobs.view-all', $legacy, true)) $allow('jobs', ['view'], 'all_records');
        if (in_array('jobs.create', $legacy, true)) $allow('jobs', ['create'], $scope);
        if (in_array('jobs.manage-all', $legacy, true)) $allow('jobs', ['view', 'edit_all', 'delete', 'assign', 'override', 'manage'], 'all_records');
        if (in_array('tasks.view-assigned', $legacy, true)) $allow('tasks', ['view', 'edit_own'], 'assigned_jobs');
        if (in_array('tasks.view-all', $legacy, true)) $allow('tasks', ['view', 'edit_all', 'assign', 'manage'], 'all_records');
        if (in_array('clients.manage', $legacy, true)) $allow('clients', ['view', 'create', 'edit_own', 'edit_all', 'delete', 'manage'], $scope === 'assigned_jobs' ? 'selected_clients' : $scope);
        if (in_array('documents.manage', $legacy, true)) $allow('documents', ['view', 'create', 'link', 'edit_own', 'edit_all', 'delete', 'export', 'manage'], $scope);
        if (in_array('documents.link', $legacy, true)) $allow('documents', ['view', 'create', 'link'], $scope);
        if (in_array('reports.view', $legacy, true)) $allow('reports', ['view', 'export'], $scope);
        if (in_array('workflows.manage', $legacy, true)) $allow('workflow', ['view', 'create', 'edit_all', 'delete', 'manage'], 'all_records');
        if (in_array('master-data.manage', $legacy, true)) $allow('masterdata', ['view', 'create', 'edit_all', 'delete', 'manage'], 'all_records');
        if (in_array('administration.manage', $legacy, true)) $allow('users', ['view', 'create', 'edit_all', 'assign', 'manage'], 'all_records');
        if (in_array('financials.view', $legacy, true)) $allow('invoice', ['view', 'export'], $scope);

        return $matrix;
    }

    private function defaultSensitiveFields(Role $role): array
    {
        return match ($this->profile($role)) {
            'admin', 'management' => $this->catalog->sensitiveFieldCodes(),
            'accounts' => ['gross_margin', 'client_target_price', 'confirmed_selling_price', 'invoice_amount', 'payment_history', 'internal_management_notes', 'client_contact_details'],
            'sales' => ['client_target_price', 'confirmed_selling_price', 'client_contact_details'],
            'jobmanager' => array_values(array_diff($this->catalog->sensitiveFieldCodes(), ['supplier_banking_details'])),
            'designer' => ['client_contact_details'],
            default => [],
        };
    }

    private function profile(Role $role): string
    {
        $value = strtoupper((string) ($role->code ?: $role->slug));
        return match (true) {
            in_array($value, ['SUPER_ADMIN', 'ADMIN', 'ADMINISTRATOR', 'SYS_ADMIN', 'SYSTEM_ADMINISTRATOR'], true) => 'admin',
            in_array($value, ['MANAGEMENT', 'OPERATIONS_MANAGER'], true) => 'management',
            in_array($value, ['JOB_MANAGER'], true) => 'jobmanager',
            in_array($value, ['SALES', 'SALES_USER', 'SALES_MANAGER'], true) => 'sales',
            in_array($value, ['DESIGNER', 'DESIGN_USER'], true) => 'designer',
            in_array($value, ['SOURCING', 'SOURCING_COORDINATOR'], true) => 'sourcing',
            in_array($value, ['PRODUCTION', 'PRODUCTION_USER'], true) => 'production',
            in_array($value, ['SHIPMENT', 'SHIPMENT_USER'], true) => 'shipment',
            in_array($value, ['ACCOUNTS', 'ACCOUNTS_USER'], true) => 'accounts',
            in_array($value, ['AUDITOR', 'READ_ONLY_AUDITOR'], true) => 'auditor',
            in_array($value, ['DOCUMENT_LINKER', 'DOCUMENT_UPLOADER', 'DOCUMENT_UPLOADER_WORKFLOW_LINKER'], true) => 'document_linker',
            default => 'member',
        };
    }

    public function isAdministratorRole(Role $role): bool
    {
        return in_array(strtoupper((string) ($role->code ?: $role->slug)), config('flowtrack.administrator_role_codes', []), true);
    }

    private function fullMatrix(): array
    {
        return collect($this->catalog->moduleCodes())
            ->mapWithKeys(fn (string $code) => [$code => [
                'scope' => 'all_records',
                'actions' => $this->catalog->actionCodes(),
            ]])
            ->all();
    }

    private function writeMatrix(Role $role, array $matrix): void
    {
        foreach ($matrix as $moduleCode => $row) {
            $role->moduleAccess()->updateOrCreate(
                ['module_code' => $moduleCode],
                ['record_scope' => $row['scope'], 'actions' => array_values($row['actions'])],
            );
        }
        $role->moduleAccess()->whereNotIn('module_code', array_keys($matrix))->delete();
    }

    private function grantsAdministration(array $matrix): bool
    {
        $actions = $matrix['users']['actions'] ?? [];
        return count(array_intersect($actions, ['create', 'edit_all', 'delete', 'assign', 'manage'])) > 0;
    }

    private function syncLegacyPermissions(Role $role): void
    {
        $matrix = $role->moduleAccess->keyBy('module_code');
        $slugs = [];
        $has = fn (string $module, string $action): bool => in_array($action, $matrix->get($module)?->actions ?? [], true);
        $scope = fn (string $module): string => $matrix->get($module)?->record_scope ?? 'none';
        $hasAny = fn (string $module, array $actions): bool => count(array_intersect($matrix->get($module)?->actions ?? [], $actions)) > 0;

        if ($has('dashboard', 'view')) $slugs[] = 'dashboard.view';
        if ($has('jobs', 'view')) $slugs[] = 'jobs.view-assigned';
        if ($has('jobs', 'view') && $scope('jobs') === 'all_records') $slugs[] = 'jobs.view-all';
        if ($has('jobs', 'create')) $slugs[] = 'jobs.create';
        if ($hasAny('jobs', ['edit_all', 'delete', 'assign', 'override', 'manage'])) $slugs[] = 'jobs.manage-all';
        if ($has('tasks', 'view')) $slugs[] = 'tasks.view-assigned';
        if ($hasAny('tasks', ['edit_all', 'assign', 'manage']) || ($has('tasks', 'view') && $scope('tasks') === 'all_records')) $slugs[] = 'tasks.view-all';
        if ($hasAny('clients', ['create', 'edit_own', 'edit_all', 'delete', 'manage'])) $slugs[] = 'clients.manage';
        if ($hasAny('documents', ['create', 'edit_own', 'edit_all', 'delete', 'manage'])) $slugs[] = 'documents.manage';
        if ($has('documents', 'link')) $slugs[] = 'documents.link';
        if ($hasAny('reports', ['view', 'export'])) $slugs[] = 'reports.view';
        if ($hasAny('invoice', ['view', 'create', 'edit_own', 'edit_all', 'export', 'manage'])) $slugs[] = 'financials.view';
        if ($hasAny('workflow', ['create', 'edit_all', 'delete', 'manage'])) $slugs[] = 'workflows.manage';
        if ($hasAny('masterdata', ['create', 'edit_all', 'delete', 'manage'])) $slugs[] = 'master-data.manage';
        if ($hasAny('users', ['create', 'edit_all', 'delete', 'assign', 'manage'])) $slugs[] = 'administration.manage';

        $managed = [
            'dashboard.view', 'jobs.view-assigned', 'jobs.view-all', 'jobs.create', 'jobs.manage-all',
            'tasks.view-assigned', 'tasks.view-all', 'clients.manage', 'documents.manage', 'documents.link', 'reports.view',
            'financials.view', 'workflows.manage', 'master-data.manage', 'administration.manage',
        ];
        $preserved = $role->permissions()->whereNotIn('slug', $managed)->pluck('permissions.id');
        $mapped = Permission::query()->whereIn('slug', array_unique($slugs))->pluck('id');
        $role->permissions()->sync($preserved->merge($mapped)->unique()->values());
    }
}
