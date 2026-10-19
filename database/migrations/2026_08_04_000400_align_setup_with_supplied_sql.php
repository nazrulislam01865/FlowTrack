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
        if (!Schema::hasTable('workspaces')) {
            Schema::create('workspaces', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('timezone', 64)->default('Asia/Dhaka');
                $table->string('default_currency', 3)->default('USD');
                $table->string('logo_path')->nullable();
                $table->string('favicon_path')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!DB::table('workspaces')->where('id', 1)->exists()) {
            DB::table('workspaces')->insert([
                'id' => 1,
                'name' => 'FlowTrack',
                'slug' => 'flowtrack',
                'timezone' => 'Asia/Dhaka',
                'default_currency' => 'USD',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!Schema::hasTable('master_records')) {
            Schema::create('master_records', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('type', 40);
                $table->string('code', 40);
                $table->string('name');
                $table->text('description')->nullable();
                $table->json('metadata')->nullable();
                $table->string('status', 20)->default('active');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['workspace_id', 'type', 'code'], 'master_records_workspace_type_code_uq');
                $table->index(['workspace_id', 'type', 'status'], 'master_records_workspace_type_status_idx');
            });
        }

        $groupMap = [
            'product_categories' => 'product_category',
            'products' => 'product',
            'shipment_methods' => 'shipment_method',
            'currencies' => 'currency',
            'document_categories' => 'document_category',
            'priorities' => 'priority',
            'task_statuses' => 'task_status',
            'departments' => 'department',
            'suppliers' => 'supplier',
            'production_units' => 'production_unit',
        ];

        if (Schema::hasTable('master_values')) {
            foreach (DB::table('master_values')->orderBy('id')->get() as $legacy) {
                $type = $groupMap[$legacy->group_key] ?? Str::singular((string) $legacy->group_key);
                DB::table('master_records')->updateOrInsert(
                    ['workspace_id' => 1, 'type' => $type, 'code' => $legacy->code],
                    [
                        'parent_id' => null,
                        'name' => $legacy->name,
                        'description' => $legacy->description,
                        'metadata' => $legacy->meta ?? null,
                        'status' => $legacy->is_active ? 'active' : 'inactive',
                        'sort_order' => (int) $legacy->id,
                        'created_at' => $legacy->created_at ?? now(),
                        'updated_at' => $legacy->updated_at ?? now(),
                        'deleted_at' => null,
                    ]
                );
            }
        }

        if (Schema::hasTable('task_packs')) {
            Schema::table('task_packs', function (Blueprint $table) {
                if (!Schema::hasColumn('task_packs', 'workspace_id')) $table->unsignedBigInteger('workspace_id')->default(1)->after('id');
                if (!Schema::hasColumn('task_packs', 'code')) $table->string('code', 40)->nullable()->after('workspace_id');
            });

            foreach (DB::table('task_packs')->orderBy('id')->get() as $pack) {
                if (blank($pack->code ?? null)) {
                    $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($pack->slug ?? $pack->name)), 0, 8));
                    $base = $base ?: 'PACK';
                    DB::table('task_packs')->where('id', $pack->id)->update(['workspace_id' => 1, 'code' => $base.'-'.$pack->id]);
                }
            }
        }

        if (!Schema::hasTable('task_pack_items')) {
            Schema::create('task_pack_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('task_pack_id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedBigInteger('default_assignee_id')->nullable();
                $table->unsignedBigInteger('default_department_id')->nullable();
                $table->unsignedBigInteger('priority_id')->nullable();
                $table->unsignedBigInteger('document_category_id')->nullable();
                $table->unsignedInteger('due_offset_days')->default(1);
                $table->boolean('is_required')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index('task_pack_id');
                $table->index('default_assignee_id');
                $table->index('default_department_id');
                $table->index('priority_id');
                $table->index('document_category_id');
            });
        }

        if (Schema::hasTable('task_pack_tasks')) {
            $mediumPriority = DB::table('master_records')->where('workspace_id', 1)->where('type', 'priority')->where('code', 'MED')->value('id');
            foreach (DB::table('task_pack_tasks')->orderBy('id')->get() as $legacy) {
                $departmentId = null;
                if ($legacy->default_department_id && Schema::hasTable('departments')) {
                    $department = DB::table('departments')->where('id', $legacy->default_department_id)->first();
                    if ($department) {
                        $departmentId = DB::table('master_records')->where('workspace_id', 1)->where('type', 'department')->where('code', $department->code)->value('id');
                    }
                }
                DB::table('task_pack_items')->updateOrInsert(
                    ['id' => $legacy->id],
                    [
                        'task_pack_id' => $legacy->task_pack_id,
                        'title' => $legacy->title,
                        'description' => null,
                        'default_assignee_id' => null,
                        'default_department_id' => $departmentId,
                        'priority_id' => $mediumPriority,
                        'document_category_id' => null,
                        'due_offset_days' => max(1, (int) $legacy->sequence),
                        'is_required' => (bool) $legacy->is_required,
                        'sort_order' => max(0, (int) $legacy->sequence - 1),
                        'created_at' => $legacy->created_at ?? now(),
                        'updated_at' => $legacy->updated_at ?? now(),
                    ]
                );
            }
        }

        if (!Schema::hasTable('workflow_templates')) {
            Schema::create('workflow_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('workspace_id');
                $table->string('code', 40);
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->unique(['workspace_id', 'code'], 'workflow_templates_workspace_code_uq');
            });
        }

        if (Schema::hasTable('workflows')) {
            foreach (DB::table('workflows')->orderBy('id')->get() as $legacy) {
                $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($legacy->slug ?? $legacy->name)), 0, 20));
                $code = $code ?: 'WF'.$legacy->id;
                DB::table('workflow_templates')->updateOrInsert(
                    ['id' => $legacy->id],
                    [
                        'workspace_id' => 1,
                        'code' => $code,
                        'name' => $legacy->name,
                        'description' => $legacy->description,
                        'is_active' => (bool) $legacy->is_active,
                        'is_default' => $legacy->id === (int) DB::table('workflows')->where('is_active', true)->min('id'),
                        'version' => 1,
                        'created_at' => $legacy->created_at ?? now(),
                        'updated_at' => $legacy->updated_at ?? now(),
                    ]
                );
            }
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table) {
                if (!Schema::hasColumn('tasks', 'setup_assignee_id')) $table->unsignedBigInteger('setup_assignee_id')->nullable()->after('assignee_id');
                if (!Schema::hasColumn('tasks', 'document_category_id')) $table->unsignedBigInteger('document_category_id')->nullable()->after('task_pack_task_id');
                if (!Schema::hasColumn('tasks', 'document_requirement_source')) $table->string('document_requirement_source', 30)->nullable()->after('document_category_id');
            });
        }

        if (Schema::hasTable('workflow_phases')) {
            Schema::table('workflow_phases', function (Blueprint $table) {
                if (!Schema::hasColumn('workflow_phases', 'workflow_template_id')) $table->unsignedBigInteger('workflow_template_id')->nullable()->after('id');
                if (!Schema::hasColumn('workflow_phases', 'document_category_id')) $table->unsignedBigInteger('document_category_id')->nullable()->after('task_pack_id');
                if (!Schema::hasColumn('workflow_phases', 'is_skippable')) $table->boolean('is_skippable')->default(false)->after('allow_job_start');
                if (!Schema::hasColumn('workflow_phases', 'auto_advance_on_ready')) $table->boolean('auto_advance_on_ready')->default(false)->after('requires_approval');
                if (!Schema::hasColumn('workflow_phases', 'is_active')) $table->boolean('is_active')->default(true)->after('auto_advance_on_ready');
                if (!Schema::hasColumn('workflow_phases', 'entry_condition')) $table->string('entry_condition')->nullable()->after('is_active');
                if (!Schema::hasColumn('workflow_phases', 'exit_condition')) $table->string('exit_condition')->nullable()->after('entry_condition');
            });

            foreach (DB::table('workflow_phases')->orderBy('id')->get() as $phase) {
                $documentId = null;
                if (!blank($phase->required_document ?? null)) {
                    $documentId = DB::table('master_records')->where('workspace_id', 1)->where('type', 'document_category')->where('name', $phase->required_document)->value('id');
                }
                DB::table('workflow_phases')->where('id', $phase->id)->update([
                    'workflow_template_id' => $phase->workflow_template_id ?? $phase->workflow_id,
                    'document_category_id' => $phase->document_category_id ?? $documentId,
                    'is_skippable' => $phase->is_skippable ?? (bool) $phase->can_skip,
                    'auto_advance_on_ready' => $phase->auto_advance_on_ready ?? false,
                    'is_active' => $phase->is_active ?? true,
                    'entry_condition' => $phase->entry_condition ?? $phase->entry_rule,
                    'exit_condition' => $phase->exit_condition ?? $phase->exit_rule,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Compatibility migration is intentionally non-destructive on rollback.
    }
};
