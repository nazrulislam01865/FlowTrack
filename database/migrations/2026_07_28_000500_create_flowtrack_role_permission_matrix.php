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
        Schema::table('roles', function (Blueprint $table) {
            $table->string('code', 80)->nullable()->after('slug');
            $table->text('description')->nullable()->after('code');
            $table->string('default_scope', 40)->default('assigned_jobs')->after('description');
            $table->boolean('is_active')->default(true)->after('is_system');
            $table->json('sensitive_fields')->nullable()->after('is_active');
        });

        $used = [];
        DB::table('roles')->orderBy('id')->get()->each(function ($role) use (&$used): void {
            $base = Str::of($role->slug ?: $role->name)
                ->ascii()
                ->upper()
                ->replaceMatches('/[^A-Z0-9]+/', '_')
                ->trim('_')
                ->substr(0, 70)
                ->value() ?: 'ROLE';

            $workspaceKey = (string) ($role->workspace_id ?? 'global');
            $candidate = $base;
            $suffix = 2;
            while (isset($used[$workspaceKey][$candidate])) {
                $candidate = Str::limit($base, 70, '').'_'.($suffix++);
            }
            $used[$workspaceKey][$candidate] = true;

            $scope = match ($role->slug) {
                'administrator', 'operations-manager' => 'all_records',
                'sales-manager' => 'selected_clients',
                default => 'assigned_jobs',
            };

            DB::table('roles')->where('id', $role->id)->update([
                'code' => $candidate,
                'description' => $role->description ?? null,
                'default_scope' => $scope,
                'is_active' => true,
                'sensitive_fields' => json_encode([]),
            ]);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['workspace_id', 'code'], 'roles_workspace_code_unique');
        });

        Schema::create('role_module_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('module_code', 50);
            $table->string('record_scope', 40)->default('none');
            $table->json('actions');
            $table->timestamps();
            $table->unique(['role_id', 'module_code'], 'role_module_access_unique');
            $table->index(['module_code', 'record_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_module_access');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_workspace_code_unique');
            $table->dropColumn(['code', 'description', 'default_scope', 'is_active', 'sensitive_fields']);
        });
    }
};
