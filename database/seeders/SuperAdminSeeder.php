<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) config('flowtrack.super_admin.email'));
        $password = (string) config('flowtrack.super_admin.password');
        $name = trim((string) config('flowtrack.super_admin.name')) ?: 'FlowTrack Super Admin';

        if ($email === '' || $password === '') {
            throw new RuntimeException('SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD must be set before seeding.');
        }

        $role = Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Super Admin', 'is_system' => true, 'workspace_id' => 1, 'code' => 'SUPER_ADMIN', 'description' => 'Super Admin with unrestricted FlowTrack access.', 'default_scope' => 'all_records', 'is_active' => true]);
        $department = Department::firstOrCreate(['code' => 'MGT'], ['name' => 'Management', 'description' => 'Operations and leadership']);

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role_id' => $role->id,
                'department_id' => $department->id,
                'is_super_admin' => true,
                'is_active' => true,
                'locale' => 'en',
                'email_verified_at' => now(),
            ],
        );
        $user->roles()->syncWithoutDetaching([$role->id]);
        WorkspaceMembership::updateOrCreate(['workspace_id'=>1,'user_id'=>$user->id], ['role_id'=>$role->id,'department_id'=>$department->id,'job_title'=>'Super Administrator','status'=>'active','joined_at'=>$user->created_at ?: now()]);
    }
}
