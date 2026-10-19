<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roles')) {
            Schema::table('roles', function (Blueprint $table) {
                if (!Schema::hasColumn('roles', 'workspace_id')) $table->unsignedBigInteger('workspace_id')->nullable()->after('id');
                if (!Schema::hasColumn('roles', 'code')) $table->string('code', 80)->nullable()->after('slug');
                if (!Schema::hasColumn('roles', 'description')) $table->text('description')->nullable()->after('code');
                if (!Schema::hasColumn('roles', 'default_scope')) $table->string('default_scope', 40)->default('assigned_jobs')->after('description');
                if (!Schema::hasColumn('roles', 'is_active')) $table->boolean('is_active')->default(true)->after('is_system');
                if (!Schema::hasColumn('roles', 'sensitive_fields')) $table->json('sensitive_fields')->nullable()->after('is_active');
            });

            foreach (DB::table('roles')->get() as $role) {
                $slug = (string) $role->slug;
                $isAdmin = in_array($slug, ['super-admin', 'admin', 'administrator'], true);
                DB::table('roles')->where('id', $role->id)->update([
                    'workspace_id' => $role->workspace_id ?? 1,
                    'code' => $role->code ?? Str::upper(Str::replace('-', '_', $slug)),
                    'description' => $role->description ?? ($isAdmin ? 'Administrator with unrestricted FlowTrack access.' : null),
                    'default_scope' => ($isAdmin || $slug === 'operations-manager') ? 'all_records' : ($role->default_scope ?: 'assigned_jobs'),
                    'is_active' => $role->is_active ?? true,
                    'sensitive_fields' => $role->sensitive_fields ?? ($isAdmin ? json_encode([
                        'supplier_cost','gross_margin','client_target_price','confirmed_selling_price','invoice_amount','payment_history','internal_management_notes','client_contact_details','supplier_banking_details'
                    ]) : json_encode([])),
                ]);
            }
        }

        if (Schema::hasTable('roles') && !DB::table('roles')->where('slug', 'admin')->exists()) {
            DB::table('roles')->insert([
                'workspace_id' => 1,
                'name' => 'Admin',
                'slug' => 'admin',
                'code' => 'ADMIN',
                'description' => 'Admin with unrestricted FlowTrack access.',
                'default_scope' => 'all_records',
                'is_system' => 1,
                'is_active' => 1,
                'sensitive_fields' => json_encode(['supplier_cost','gross_margin','client_target_price','confirmed_selling_price','invoice_amount','payment_history','internal_management_notes','client_contact_details','supplier_banking_details']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }


        // Recommended operational roles from the supplied FlowTrack access-control matrix.
        // Existing roles are never renamed or reassigned; missing roles are added so an
        // administrator can assign them immediately after migration.
        if (Schema::hasTable('roles')) {
            $recommendedRoles = [
                ['Management','management','MANAGEMENT','Organization-wide visibility and exception management.','all_records'],
                ['Job Manager','job-manager','JOB_MANAGER','Manages assigned Jobs, phases, people and tasks.','assigned_jobs'],
                ['Sales User','sales-user','SALES','Creates and maintains client, Job and quotation records for assigned work.','assigned_jobs'],
                ['Designer','designer','DESIGNER','Works on assigned artwork tasks and document versions.','assigned_jobs'],
                ['Sourcing Coordinator','sourcing-coordinator','SOURCING','Coordinates supplier costing, samples and sourcing tasks.','assigned_jobs'],
                ['Production User','production-user','PRODUCTION','Updates assigned production tasks and production evidence.','assigned_jobs'],
                ['Shipment User','shipment-user','SHIPMENT','Updates assigned shipment tasks, tracking and documents.','assigned_jobs'],
                ['Accounts User','accounts-user','ACCOUNTS','Handles assigned invoice, payment and reporting work.','assigned_jobs'],
                ['General Team Member','general-team-member','TEAM_MEMBER','Views and updates only work assigned to the user.','assigned_jobs'],
                ['Read-only Auditor','read-only-auditor','AUDITOR','Read/export access without operational updates.','all_records'],
            ];
            foreach ($recommendedRoles as [$name,$slug,$code,$description,$scope]) {
                if (!DB::table('roles')->where('slug', $slug)->exists()) {
                    DB::table('roles')->insert([
                        'workspace_id' => 1, 'name' => $name, 'slug' => $slug, 'code' => $code,
                        'description' => $description, 'default_scope' => $scope,
                        'is_system' => 1, 'is_active' => 1, 'sensitive_fields' => json_encode([]),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('permissions') && !Schema::hasColumn('permissions', 'group')) {
            Schema::table('permissions', function (Blueprint $table) {
                $table->string('group', 50)->nullable()->after('slug');
            });
            foreach (DB::table('permissions')->get() as $permission) {
                DB::table('permissions')->where('id', $permission->id)->update([
                    'group' => ucfirst((string) Str::before($permission->slug, '.')),
                ]);
            }
        }

        if (!Schema::hasTable('role_module_access')) {
            Schema::create('role_module_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->string('module_code', 50);
                $table->string('record_scope', 40)->default('none');
                $table->json('actions');
                $table->timestamps();
                $table->unique(['role_id', 'module_code']);
                $table->index(['module_code', 'record_scope']);
            });
        }

        if (!Schema::hasTable('workspace_memberships')) {
            Schema::create('workspace_memberships', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->default(1);
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->string('job_title')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();
                $table->unique(['workspace_id', 'user_id']);
                $table->index(['role_id', 'status', 'workspace_id', 'user_id'], 'ft_membership_role_active_workspace_idx');
            });
        }

        if (Schema::hasTable('users') && Schema::hasTable('workspace_memberships')) {
            foreach (DB::table('users')->whereNotNull('role_id')->get() as $user) {
                DB::table('workspace_memberships')->updateOrInsert(
                    ['workspace_id' => 1, 'user_id' => $user->id],
                    [
                        'role_id' => $user->role_id,
                        'department_id' => $user->department_id,
                        'status' => ($user->is_active ?? true) ? 'active' : 'inactive',
                        'joined_at' => $user->created_at ?? now(),
                        'created_at' => $user->created_at ?? now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }

        $modules = ['dashboard','clients','jobs','tasks','quotation','artwork','sample','production','shipment','invoice','documents','reports','workflow','masterdata','users','audit','notifications'];
        $allActions = ['view','create','edit_own','edit_all','delete','assign','approve','link','export','override','manage'];

        $rolePresets = [
            'management' => ['scope'=>'all_records','modules'=>[
                'dashboard'=>['view'],'clients'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'jobs'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'tasks'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'quotation'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'artwork'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'sample'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'production'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'shipment'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'invoice'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'documents'=>['view','create','edit_own','edit_all','assign','approve','link','export','override'],
                'reports'=>['view','export'],'audit'=>['view','export'],'notifications'=>['view'],
            ]],
            'job-manager' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'clients'=>['view','create','edit_own','edit_all','assign','export'],
                'jobs'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'tasks'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'quotation'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'artwork'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'sample'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'production'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'shipment'=>['view','create','edit_own','edit_all','assign','approve','export','override'],
                'documents'=>['view','create','edit_own','edit_all','link','export'],'reports'=>['view','export'],'notifications'=>['view'],
            ]],
            'sales-user' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'clients'=>['view','create','edit_own','assign','export'],
                'jobs'=>['view','create','edit_own','assign','export'],'tasks'=>['view','create','edit_own','assign'],
                'quotation'=>['view','create','edit_own','export'],'documents'=>['view','create','link','export'],'notifications'=>['view'],
            ]],
            'designer' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'jobs'=>['view'],'tasks'=>['view','create','edit_own'],
                'artwork'=>['view','create','edit_own','export'],'documents'=>['view','create','link','export'],'notifications'=>['view'],
            ]],
            'sourcing-coordinator' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'jobs'=>['view'],'tasks'=>['view','create','edit_own'],
                'quotation'=>['view','create','edit_own','export'],'sample'=>['view','create','edit_own','export'],
                'production'=>['view','create','edit_own','export'],'documents'=>['view','create','link','export'],'notifications'=>['view'],
            ]],
            'production-user' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'jobs'=>['view'],'tasks'=>['view','create','edit_own'],
                'production'=>['view','create','edit_own'],'documents'=>['view','create','link'],'notifications'=>['view'],
            ]],
            'shipment-user' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'jobs'=>['view'],'tasks'=>['view','create','edit_own'],
                'shipment'=>['view','create','edit_own','export'],'documents'=>['view','create','link','export'],'notifications'=>['view'],
            ]],
            'accounts-user' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'clients'=>['view'],'jobs'=>['view'],'tasks'=>['view','create','edit_own'],
                'invoice'=>['view','create','edit_own','edit_all','approve','export'],
                'documents'=>['view','create','link','export'],'reports'=>['view','export'],'notifications'=>['view'],
            ]],
            'general-team-member' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'jobs'=>['view'],'tasks'=>['view','edit_own'],'documents'=>['view','create','link'],'notifications'=>['view'],
            ]],
            'member' => ['scope'=>'assigned_jobs','modules'=>[
                'dashboard'=>['view'],'jobs'=>['view'],'tasks'=>['view','edit_own'],'documents'=>['view','create','link'],'notifications'=>['view'],
            ]],
            'read-only-auditor' => ['scope'=>'all_records','modules'=>[
                'dashboard'=>['view'],'clients'=>['view','export'],'jobs'=>['view','export'],'tasks'=>['view','export'],
                'quotation'=>['view','export'],'artwork'=>['view','export'],'sample'=>['view','export'],'production'=>['view','export'],
                'shipment'=>['view','export'],'invoice'=>['view','export'],'documents'=>['view','export'],'reports'=>['view','export'],
                'audit'=>['view','export'],'notifications'=>['view'],
            ]],
        ];

        if (Schema::hasTable('role_module_access')) {
            foreach (DB::table('roles')->get() as $role) {
                $slug = (string) $role->slug;
                $admin = in_array($slug, ['super-admin','admin','administrator'], true);
                $preset = $rolePresets[$slug] ?? null;
                $scope = $admin || $slug === 'operations-manager' ? 'all_records' : ($preset['scope'] ?? 'assigned_jobs');
                $perms = DB::table('permission_role')
                    ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                    ->where('permission_role.role_id', $role->id)
                    ->pluck('permissions.slug')->all();

                foreach ($modules as $module) {
                    if (DB::table('role_module_access')->where('role_id', $role->id)->where('module_code', $module)->exists()) continue;

                    if ($admin) {
                        $actions = $allActions;
                    } elseif ($preset) {
                        $actions = $preset['modules'][$module] ?? [];
                    } else {
                        $actions = [];
                        $has = fn (string $permission) => in_array($permission, $perms, true);
                        $legacyModule = match ($module) {
                            'workflow' => 'workflow',
                            'masterdata' => 'master',
                            'users' => 'users',
                            default => $module,
                        };

                        if ($has($legacyModule.'.view') || ($module === 'jobs' && $has('jobs.view')) || ($module === 'tasks' && $has('tasks.view')) || ($module === 'documents' && $has('documents.view'))) $actions[] = 'view';
                        if ($has($legacyModule.'.create')) $actions[] = 'create';
                        if ($has($legacyModule.'.update')) $actions[] = 'edit_own';
                        if ($module === 'documents' && $has('documents.view')) $actions = array_merge($actions, ['create','link']);
                        if ($module === 'reports' && $has('reports.view')) $actions[] = 'export';
                        if ($module === 'workflow' && $has('workflow.manage')) $actions = array_merge($actions, ['view','create','edit_all','delete','manage']);
                        if ($module === 'masterdata' && $has('master.manage')) $actions = array_merge($actions, ['view','create','edit_all','delete','manage']);
                        if ($module === 'users' && $has('users.manage')) $actions = array_merge($actions, ['view','create','edit_all','assign','manage']);
                        if ($module === 'notifications' && $has('notifications.view')) $actions[] = 'view';

                        if ($slug === 'operations-manager') {
                            if (in_array($module, ['dashboard','clients','jobs','tasks','documents','reports','notifications'], true)) {
                                $actions = array_values(array_unique(array_merge($actions, ['view','create','edit_own','edit_all','assign','link','export'])));
                            }
                        }
                        $actions = array_values(array_unique($actions));
                    }

                    DB::table('role_module_access')->insert([
                        'role_id' => $role->id,
                        'module_code' => $module,
                        'record_scope' => $actions ? $scope : 'none',
                        'actions' => json_encode($actions),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Compatibility migration intentionally keeps role/access data on rollback.
    }
};
