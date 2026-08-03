<?php

namespace App\Http\Controllers\FlowTrack\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\RoleMatrixCatalog;
use App\Modules\OrderFlow\Business\Services\RoleMatrixService;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\Role;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccessController extends Controller
{
    public function storeRole(Request $request, RoleMatrixCatalog $catalog, RoleMatrixService $service): JsonResponse
    {
        $this->authorizeAccess($request);
        $workspaceId = $request->user()->currentWorkspaceId();
        $request->merge(['code' => $this->normalizeCode((string) $request->input('code'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9_]+$/', Rule::unique('roles', 'code')->where('workspace_id', $workspaceId)],
            'description' => ['nullable', 'string', 'max:3000'],
            'default_scope' => ['required', Rule::in($catalog->scopeCodes())],
            'is_active' => ['required', 'boolean'],
            'copy_from_role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')->where('workspace_id', $workspaceId)],
        ]);

        if (in_array($data['code'], config('flowtrack.administrator_role_codes', []), true)) {
            $this->authorizeAdministrator($request);
            $data['default_scope'] = 'all_records';
            $data['is_active'] = true;
        }

        $role = $service->createRole($workspaceId, $data);

        return response()->json(['role' => $service->mapRole($role)], 201);
    }

    public function updateRole(Request $request, Role $role, RoleMatrixCatalog $catalog, RoleMatrixService $service): JsonResponse
    {
        $this->authorizeRole($request, $role);
        $this->protectSuperAdminRole($request, $role);
        $workspaceId = $request->user()->currentWorkspaceId();
        $requestedCode = $role->is_system ? (string) $role->code : (string) $request->input('code');
        $request->merge(['code' => $this->normalizeCode($requestedCode)]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9_]+$/', Rule::unique('roles', 'code')->where('workspace_id', $workspaceId)->ignore($role)],
            'description' => ['nullable', 'string', 'max:3000'],
            'default_scope' => ['required', Rule::in($catalog->scopeCodes())],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($service->isAdministratorRole($role) || in_array($data['code'], config('flowtrack.administrator_role_codes', []), true)) {
            $this->authorizeAdministrator($request);
            $data['default_scope'] = 'all_records';
            $data['is_active'] = true;
        } elseif (! $data['is_active'] && $role->memberships()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages([
                'is_active' => 'Move active users to another role before deactivating this role.',
            ]);
        }

        $updated = $service->updateRole($role, $data);

        return response()->json(['role' => $service->mapRole($updated)]);
    }

    public function destroyRole(Request $request, Role $role, RoleMatrixService $service): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $this->authorizeRole($request, $role);
        $this->protectSuperAdminRole($request, $role);

        if ($request->user()->currentWorkspaceMembership()?->role_id === $role->id) {
            throw ValidationException::withMessages(['role' => 'You cannot delete your own active role.']);
        }
        if ($role->memberships()->exists()) {
            throw ValidationException::withMessages(['role' => 'Reassign all users before deleting this role.']);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted successfully.']);
    }

    public function updateMatrix(Request $request, Role $role, RoleMatrixCatalog $catalog, RoleMatrixService $service): JsonResponse
    {
        $this->authorizeRole($request, $role);
        $this->protectSuperAdminRole($request, $role);
        if ($service->isAdministratorRole($role)) {
            $this->authorizeAdministrator($request);
        }
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1'],
            'modules.*.module_code' => ['required', 'string', Rule::in($catalog->moduleCodes()), 'distinct'],
            'modules.*.scope' => ['required', 'string', Rule::in($catalog->scopeCodes())],
            'modules.*.actions' => ['present', 'array'],
            'modules.*.actions.*' => ['string', Rule::in($catalog->actionCodes())],
            'sensitive_fields' => ['present', 'array'],
            'sensitive_fields.*' => ['string', Rule::in($catalog->sensitiveFieldCodes()), 'distinct'],
        ]);

        $updated = $service->updateMatrix($role, $data['modules'], $data['sensitive_fields'], $request->user());

        return response()->json(['role' => $service->mapRole($updated)]);
    }

    public function storeMember(Request $request): JsonResponse
    {
        $this->authorizeAdministrator($request);
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'initials' => ['nullable', 'string', 'max:8'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('is_active', true))],
            'department_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::Department->value)->where('status', 'active'))],
            'job_title' => ['nullable', 'string', 'max:255'],
            'locale' => ['nullable', Rule::in(['en', 'zh'])],
            'status' => ['nullable', Rule::in(['active', 'pending', 'inactive'])],
        ]);

        $role = Role::query()->where('workspace_id', $workspaceId)->findOrFail($data['role_id']);
        $this->assertCanAssignRole($request, $role);

        $membership = DB::transaction(function () use ($workspaceId, $data): WorkspaceMembership {
            $user = User::create([
                'name' => $data['name'],
                'initials' => ($data['initials'] ?? null) ?: $this->initials($data['name']),
                'email' => mb_strtolower($data['email']),
                'email_verified_at' => now(),
                'locale' => $data['locale'] ?? 'en',
                'status' => ($data['status'] ?? 'active') === 'active' ? 'active' : 'inactive',
                'is_super_admin' => false,
                'password' => Hash::make($data['password']),
            ]);

            return WorkspaceMembership::create([
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'role_id' => $data['role_id'],
                'department_id' => $data['department_id'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'status' => $data['status'] ?? 'active',
                'joined_at' => now(),
            ]);
        });

        return response()->json(['member' => $this->mapMember($membership)], 201);
    }

    public function updateMember(Request $request, WorkspaceMembership $membership): JsonResponse
    {
        $this->authorizeAccess($request);
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($membership->workspace_id === $workspaceId, 404);
        $membership->loadMissing(['user', 'role']);
        $this->protectSuperAdminMember($request, $membership);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($membership->user_id)],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('is_active', true))],
            'department_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::Department->value)->where('status', 'active'))],
            'job_title' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending'])],
            'locale' => ['sometimes', Rule::in(['en', 'zh'])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ]);

        $identityFields = ['name', 'email', 'password'];
        if (collect($identityFields)->contains(fn (string $field): bool => array_key_exists($field, $data))
            && ! $request->user()->isFlowAdministrator()) {
            abort(403, 'Only an Admin or Super Admin can change user login details.');
        }

        $targetRole = Role::query()->where('workspace_id', $workspaceId)->findOrFail($data['role_id']);
        $this->assertCanAssignRole($request, $targetRole);

        if ($membership->user_id === $request->user()->id) {
            if ($data['status'] !== 'active') {
                throw ValidationException::withMessages(['status' => 'You cannot deactivate your own current workspace membership.']);
            }
            if (! $targetRole->permissions()->where('slug', 'administration.manage')->exists() && ! $request->user()->isFlowAdministrator()) {
                throw ValidationException::withMessages(['role_id' => 'Your own role must retain Users & Access management.']);
            }
        }

        DB::transaction(function () use ($membership, $data): void {
            $membership->update([
                'role_id' => $data['role_id'],
                'department_id' => $data['department_id'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'status' => $data['status'],
            ]);

            $userData = [];
            if (array_key_exists('name', $data)) {
                $userData['name'] = $data['name'];
                $userData['initials'] = $this->initials($data['name']);
            }
            if (array_key_exists('email', $data)) {
                $userData['email'] = mb_strtolower($data['email']);
                $userData['email_verified_at'] = now();
            }
            if (array_key_exists('locale', $data)) {
                $userData['locale'] = $data['locale'] ?: 'en';
            }
            if (! empty($data['password'])) {
                $userData['password'] = Hash::make($data['password']);
            }
            $userData['status'] = $data['status'] === 'active' ? 'active' : 'inactive';
            $membership->user->update($userData);
        });

        return response()->json(['member' => $this->mapMember($membership->fresh())]);
    }

    public function destroyMember(Request $request, WorkspaceMembership $membership): JsonResponse
    {
        $this->authorizeAdministrator($request);
        abort_unless($membership->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $membership->loadMissing(['user', 'role']);
        $this->protectSuperAdminMember($request, $membership);

        if ($membership->user_id === $request->user()->id) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own signed-in account.']);
        }

        DB::transaction(function () use ($membership): void {
            $user = $membership->user;
            $membership->delete();
            if ($user && ! $user->workspaceMemberships()->exists()) {
                $user->delete();
            }
        });

        return response()->json(['message' => 'User deleted successfully.']);
    }

    private function authorizeRole(Request $request, Role $role): void
    {
        $this->authorizeAccess($request);
        abort_unless($role->workspace_id === $request->user()->currentWorkspaceId(), 404);
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->hasFlowAction('users', 'manage') || $request->user()?->isFlowAdministrator(), 403);
    }

    private function authorizeAdministrator(Request $request): void
    {
        abort_unless($request->user()?->isFlowAdministrator(), 403, 'Only an Admin or Super Admin can perform this action.');
    }

    private function protectSuperAdminRole(Request $request, Role $role): void
    {
        if (strtoupper((string) $role->code) === 'SUPER_ADMIN' && ! $request->user()->isFlowSuperAdmin()) {
            abort(403, 'Only the Super Admin can change the Super Admin role.');
        }
    }

    private function protectSuperAdminMember(Request $request, WorkspaceMembership $membership): void
    {
        if (($membership->user?->is_super_admin || strtoupper((string) $membership->role?->code) === 'SUPER_ADMIN') && ! $request->user()->isFlowSuperAdmin()) {
            abort(403, 'Only the Super Admin can change a Super Admin account.');
        }
    }

    private function assertCanAssignRole(Request $request, Role $role): void
    {
        $code = strtoupper((string) $role->code);

        if ($code === 'SUPER_ADMIN' && ! $request->user()->isFlowSuperAdmin()) {
            throw ValidationException::withMessages(['role_id' => 'Only the Super Admin can assign the Super Admin role.']);
        }

        if (in_array($code, config('flowtrack.administrator_role_codes', []), true)
            && ! $request->user()->isFlowAdministrator()) {
            throw ValidationException::withMessages(['role_id' => 'Only an Admin or Super Admin can assign an administrator role.']);
        }
    }

    private function mapMember(WorkspaceMembership $membership): array
    {
        $membership->load(['user:id,name,initials,email,status,is_super_admin,locale', 'role:id,name,code', 'department:id,name']);

        return [
            'id' => $membership->id,
            'userId' => $membership->user_id,
            'name' => $membership->user?->name,
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
        ];
    }

    private function normalizeCode(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '_')
            ->trim('_')
            ->substr(0, 80)
            ->value();
    }

    private function initials(string $value): string
    {
        return Str::of($value)->explode(' ')->filter()->map(fn (string $part) => mb_substr($part, 0, 1))->take(2)->implode('');
    }
}
