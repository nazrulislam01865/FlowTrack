<?php

namespace Database\Seeders\FlowTrack;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\RoleMatrixCatalog;
use App\Modules\OrderFlow\Data\Models\Permission;
use App\Modules\OrderFlow\Data\Models\Role;
use App\Modules\OrderFlow\Data\Models\Workspace;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('flowtrack.super_admin.email'));
        $password = (string) config('flowtrack.super_admin.password');
        $name = trim((string) config('flowtrack.super_admin.name')) ?: 'FlowTrack Super Admin';

        if ($email === '' || $password === '') {
            throw new RuntimeException(
                'Set FLOWTRACK_SUPER_ADMIN_EMAIL and FLOWTRACK_SUPER_ADMIN_PASSWORD in .env before running SuperAdminSeeder.'
            );
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('FLOWTRACK_SUPER_ADMIN_EMAIL must be a valid email address.');
        }

        if (mb_strlen($password) < 12 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/[0-9]/', $password)) {
            throw new RuntimeException('FLOWTRACK_SUPER_ADMIN_PASSWORD must contain at least 12 characters, including letters and numbers.');
        }

        DB::transaction(function () use ($email, $password, $name): void {
            $workspace = Workspace::query()->where('slug', config('flowtrack.workspace.slug'))->first()
                ?? Workspace::query()->first()
                ?? Workspace::create([
                    'name' => config('flowtrack.workspace.name'),
                    'slug' => config('flowtrack.workspace.slug'),
                    'timezone' => config('flowtrack.workspace.timezone'),
                    'default_currency' => config('flowtrack.workspace.currency'),
                    'is_active' => true,
                ]);

            $permissions = collect([
                ['Dashboard', 'dashboard.view', 'Workspace'],
                ['View assigned jobs', 'jobs.view-assigned', 'Jobs'],
                ['View all jobs', 'jobs.view-all', 'Jobs'],
                ['Create jobs', 'jobs.create', 'Jobs'],
                ['Manage all jobs', 'jobs.manage-all', 'Jobs'],
                ['View assigned tasks', 'tasks.view-assigned', 'Tasks'],
                ['View all tasks', 'tasks.view-all', 'Tasks'],
                ['Manage clients', 'clients.manage', 'Clients'],
                ['Manage documents', 'documents.manage', 'Documents'],
                ['View reports', 'reports.view', 'Reports'],
                ['View financial values', 'financials.view', 'Finance'],
                ['Manage workflows', 'workflows.manage', 'Administration'],
                ['Manage master data', 'master-data.manage', 'Administration'],
                ['Manage administration', 'administration.manage', 'Administration'],
            ])->map(fn (array $permission) => Permission::query()->firstOrCreate(
                ['slug' => $permission[1]],
                ['name' => $permission[0], 'group' => $permission[2]],
            ));

            $superAdminRole = $this->upsertAdministratorRole($workspace->id, 'Super Admin', 'SUPER_ADMIN', true);
            $adminRole = $this->upsertAdministratorRole($workspace->id, 'Admin', 'ADMIN', true);
            $superAdminRole->permissions()->sync($permissions->pluck('id'));
            $adminRole->permissions()->sync($permissions->pluck('id'));

            $catalog = app(RoleMatrixCatalog::class);
            foreach ([$superAdminRole, $adminRole] as $role) {
                foreach ($catalog->moduleCodes() as $moduleCode) {
                    $role->moduleAccess()->updateOrCreate(
                        ['module_code' => $moduleCode],
                        ['record_scope' => 'all_records', 'actions' => $catalog->actionCodes()],
                    );
                }
                $role->update([
                    'default_scope' => 'all_records',
                    'sensitive_fields' => $catalog->sensitiveFieldCodes(),
                    'is_active' => true,
                    'is_system' => true,
                ]);
            }

            $user = User::query()->updateOrCreate(
                ['email' => mb_strtolower($email)],
                [
                    'name' => $name,
                    'initials' => $this->initials($name),
                    'email_verified_at' => now(),
                    'locale' => 'en',
                    'status' => 'active',
                    'is_super_admin' => true,
                    'password' => Hash::make($password),
                ],
            );

            WorkspaceMembership::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $user->id],
                [
                    'role_id' => $superAdminRole->id,
                    'department_id' => null,
                    'job_title' => 'Super Administrator',
                    'status' => 'active',
                    'joined_at' => now(),
                ],
            );
        });
    }

    private function upsertAdministratorRole(int $workspaceId, string $name, string $code, bool $system): Role
    {
        return Role::query()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'code' => $code],
            [
                'name' => $name,
                'slug' => Str::of($code)->lower()->replace('_', '-')->toString(),
                'description' => "{$name} with unrestricted FlowTrack access.",
                'default_scope' => 'all_records',
                'is_system' => $system,
                'is_active' => true,
                'sensitive_fields' => [],
            ],
        );
    }

    private function initials(string $name): string
    {
        return Str::of($name)->explode(' ')->filter()->map(fn (string $part) => mb_substr($part, 0, 1))->take(2)->implode('');
    }
}
