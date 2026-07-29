<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\RoleMatrixService;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\Permission;
use App\Modules\OrderFlow\Data\Models\Role;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdministrationController extends Controller
{
    public function index(Request $request, FlowAuthorizationService $authorization): Response
    {
        abort_unless($authorization->allows($request->user(), 'users', 'view'), 403);
        $workspaceId = $request->user()->currentWorkspaceId();

        return Inertia::render('flowtrack/Administration/Index', [
            'memberships' => WorkspaceMembership::query()->where('workspace_id', $workspaceId)->with(['user', 'role.permissions', 'department'])->orderBy('id')->get(),
            'roles' => Role::query()->where('workspace_id', $workspaceId)->with(['permissions', 'moduleAccess'])->orderBy('name')->get(),
            'permissions' => Permission::query()->orderBy('group')->orderBy('name')->get()->groupBy('group'),
            'departments' => MasterRecord::query()->where('workspace_id', $workspaceId)->where('type', MasterDataType::Department->value)->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(),
            'canCreateUsers' => $authorization->isAdministrator($request->user()),
            'isSuperAdmin' => $authorization->isSuperAdmin($request->user()),
        ]);
    }

    public function storeRole(Request $request, FlowAuthorizationService $authorization, RoleMatrixService $matrix): RedirectResponse
    {
        $this->authorizeRoleManagement($request, $authorization);
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('roles', 'slug')->where('workspace_id', $workspaceId)],
            'permission_ids' => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        DB::transaction(function () use ($workspaceId, $data, $matrix): void {
            $role = Role::create([
                'workspace_id' => $workspaceId,
                'name' => $data['name'],
                'slug' => str($data['slug'])->lower()->toString(),
                'code' => str($data['slug'])->upper()->replace('-', '_')->toString(),
                'is_system' => false,
                'is_active' => true,
            ]);
            $role->permissions()->sync($data['permission_ids']);
            $matrix->ensureInitialized($role->fresh(['permissions', 'moduleAccess']));
        });

        return back()->with('success', 'Role created successfully.');
    }

    public function updateRole(Request $request, Role $role, FlowAuthorizationService $authorization, RoleMatrixService $matrix): RedirectResponse
    {
        $this->authorizeRoleManagement($request, $authorization);
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($role->workspace_id === $workspaceId, 404);
        $this->protectSuperAdminRole($request, $role, $authorization);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('roles', 'slug')->where('workspace_id', $workspaceId)->ignore($role)],
            'permission_ids' => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        DB::transaction(function () use ($role, $data, $matrix): void {
            $role->update([
                'name' => $data['name'],
                'slug' => str($data['slug'])->lower()->toString(),
            ]);
            $role->permissions()->sync($data['permission_ids']);
            $role->moduleAccess()->delete();
            $matrix->ensureInitialized($role->fresh(['permissions', 'moduleAccess']));
        });

        return back()->with('success', 'Role permissions updated and synchronized with the permission matrix.');
    }

    public function destroyRole(Request $request, Role $role, FlowAuthorizationService $authorization): RedirectResponse
    {
        $this->authorizeRoleManagement($request, $authorization);
        abort_unless($role->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $this->protectSuperAdminRole($request, $role, $authorization);

        if ($role->memberships()->exists()) {
            throw ValidationException::withMessages(['role' => 'Reassign or remove every user from this role before deleting it.']);
        }
        if ($request->user()->currentWorkspaceMembership()?->role_id === $role->id) {
            throw ValidationException::withMessages(['role' => 'You cannot delete your own active role.']);
        }

        $role->delete();

        return back()->with('success', 'Role deleted successfully.');
    }

    public function storeMember(Request $request, FlowAuthorizationService $authorization): RedirectResponse
    {
        $this->authorizeAdministrator($request, $authorization);
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'initials' => ['nullable', 'string', 'max:8'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('is_active', true))],
            'department_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::Department->value)->where('status', 'active'))],
            'job_title' => ['nullable', 'string', 'max:255'],
        ]);

        $role = Role::query()->where('workspace_id', $workspaceId)->findOrFail($data['role_id']);
        $this->assertCanAssignRole($request, $role, $authorization);

        DB::transaction(function () use ($workspaceId, $data): void {
            $user = User::create([
                'name' => $data['name'],
                'initials' => $data['initials'] ?: str($data['name'])->explode(' ')->filter()->map(fn ($part) => mb_substr($part, 0, 1))->take(2)->implode(''),
                'email' => mb_strtolower($data['email']),
                'email_verified_at' => now(),
                'password' => Hash::make($data['password']),
                'status' => 'active',
                'is_super_admin' => false,
            ]);

            WorkspaceMembership::create([
                'workspace_id' => $workspaceId,
                'user_id' => $user->id,
                'role_id' => $data['role_id'],
                'department_id' => $data['department_id'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'status' => 'active',
                'joined_at' => now(),
            ]);
        });

        return back()->with('success', 'Workspace member created successfully.');
    }

    public function updateMember(Request $request, WorkspaceMembership $membership, FlowAuthorizationService $authorization): RedirectResponse
    {
        abort_unless($authorization->allows($request->user(), 'users', 'manage') || $authorization->isAdministrator($request->user()), 403);
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($membership->workspace_id === $workspaceId, 404);
        $membership->loadMissing(['user', 'role']);
        $this->protectSuperAdminMember($request, $membership, $authorization);

        $data = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('is_active', true))],
            'department_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::Department->value))],
            'job_title' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive', 'pending'])],
        ]);

        $role = Role::query()->where('workspace_id', $workspaceId)->findOrFail($data['role_id']);
        $this->assertCanAssignRole($request, $role, $authorization);
        if ($membership->user_id === $request->user()->id && $data['status'] !== 'active') {
            throw ValidationException::withMessages(['status' => 'You cannot deactivate your own signed-in workspace membership.']);
        }

        DB::transaction(function () use ($membership, $data): void {
            $membership->update($data);
            $membership->user?->update(['status' => $data['status'] === 'active' ? 'active' : 'inactive']);
        });

        return back()->with('success', 'Member access updated.');
    }

    public function destroyMember(Request $request, WorkspaceMembership $membership, FlowAuthorizationService $authorization): RedirectResponse
    {
        $this->authorizeAdministrator($request, $authorization);
        abort_unless($membership->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $membership->loadMissing(['user', 'role']);
        $this->protectSuperAdminMember($request, $membership, $authorization);

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

        return back()->with('success', 'Workspace member deleted successfully.');
    }

    private function authorizeRoleManagement(Request $request, FlowAuthorizationService $authorization): void
    {
        abort_unless($authorization->allows($request->user(), 'users', 'manage') || $authorization->isAdministrator($request->user()), 403);
    }

    private function authorizeAdministrator(Request $request, FlowAuthorizationService $authorization): void
    {
        abort_unless($authorization->isAdministrator($request->user()), 403, 'Only an Admin or Super Admin can perform this action.');
    }

    private function protectSuperAdminRole(Request $request, Role $role, FlowAuthorizationService $authorization): void
    {
        if (strtoupper((string) $role->code) === 'SUPER_ADMIN' && ! $authorization->isSuperAdmin($request->user())) {
            abort(403, 'Only the Super Admin can change the Super Admin role.');
        }
    }

    private function protectSuperAdminMember(Request $request, WorkspaceMembership $membership, FlowAuthorizationService $authorization): void
    {
        if (($membership->user?->is_super_admin || strtoupper((string) $membership->role?->code) === 'SUPER_ADMIN')
            && ! $authorization->isSuperAdmin($request->user())) {
            abort(403, 'Only the Super Admin can change a Super Admin account.');
        }
    }

    private function assertCanAssignRole(Request $request, Role $role, FlowAuthorizationService $authorization): void
    {
        $code = strtoupper((string) $role->code);
        if ($code === 'SUPER_ADMIN' && ! $authorization->isSuperAdmin($request->user())) {
            throw ValidationException::withMessages(['role_id' => 'Only the Super Admin can assign the Super Admin role.']);
        }
        if (in_array($code, config('flowtrack.administrator_role_codes', []), true)
            && ! $authorization->isAdministrator($request->user())) {
            throw ValidationException::withMessages(['role_id' => 'Only an Admin or Super Admin can assign an administrator role.']);
        }
    }
}
