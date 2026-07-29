<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_documents', function (Blueprint $table): void {
            $table->foreignId('workflow_phase_id')->nullable()->after('flow_job_id')->constrained('workflow_phases')->nullOnDelete();
            $table->foreignId('task_pack_item_id')->nullable()->after('workflow_phase_id')->constrained('task_pack_items')->nullOnDelete();
            $table->foreignId('linked_by')->nullable()->after('uploaded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable()->after('is_final');
            $table->index(['flow_job_id', 'workflow_phase_id', 'document_category_id'], 'flow_docs_job_phase_category_idx');
        });

        // Link existing current-phase documents where the configured category can be resolved safely.
        DB::table('flow_documents as documents')
            ->join('flow_jobs as jobs', 'jobs.id', '=', 'documents.flow_job_id')
            ->join('workflow_phases as phases', 'phases.id', '=', 'jobs.current_phase_id')
            ->whereNull('documents.workflow_phase_id')
            ->whereColumn('documents.document_category_id', 'phases.document_category_id')
            ->select('documents.id', 'documents.uploaded_by', 'documents.created_at', 'jobs.current_phase_id')
            ->orderBy('documents.id')
            ->get()
            ->each(function ($document): void {
                DB::table('flow_documents')->where('id', $document->id)->update([
                    'workflow_phase_id' => $document->current_phase_id,
                    'linked_by' => $document->uploaded_by,
                    'linked_at' => $document->created_at,
                ]);
            });

        // Safely link legacy documents to a Task Pack item only when the
        // current Phase has exactly one item requiring that category. Ambiguous
        // matches remain unlinked and must be linked explicitly from Documents.
        DB::table('flow_documents as documents')
            ->join('flow_jobs as jobs', 'jobs.id', '=', 'documents.flow_job_id')
            ->join('workflow_phases as phases', 'phases.id', '=', 'jobs.current_phase_id')
            ->join('task_pack_items as items', function ($join): void {
                $join->on('items.task_pack_id', '=', 'phases.task_pack_id')
                    ->on('items.document_category_id', '=', 'documents.document_category_id');
            })
            ->whereNull('documents.workflow_phase_id')
            ->select(
                'documents.id',
                'documents.uploaded_by',
                'documents.created_at',
                'jobs.current_phase_id',
                'items.id as task_pack_item_id',
            )
            ->orderBy('documents.id')
            ->get()
            ->groupBy('id')
            ->filter(fn ($matches) => $matches->count() === 1)
            ->each(function ($matches): void {
                $document = $matches->first();
                DB::table('flow_documents')->where('id', $document->id)->update([
                    'workflow_phase_id' => $document->current_phase_id,
                    'task_pack_item_id' => $document->task_pack_item_id,
                    'linked_by' => $document->uploaded_by,
                    'linked_at' => $document->created_at,
                ]);
            });

        // Quarantine generated Tasks that were attached to a Phase but came
        // from a different Phase's Task Pack. The workflow-state sync command
        // will immediately generate the correct Tasks for the current Phase.
        $mismatchedTaskIds = DB::table('flow_tasks as tasks')
            ->join('workflow_phases as phases', 'phases.id', '=', 'tasks.workflow_phase_id')
            ->join('task_pack_items as items', 'items.id', '=', 'tasks.task_pack_item_id')
            ->whereNull('tasks.deleted_at')
            ->whereNotNull('phases.task_pack_id')
            ->whereColumn('items.task_pack_id', '!=', 'phases.task_pack_id')
            ->pluck('tasks.id');

        if ($mismatchedTaskIds->isNotEmpty()) {
            DB::table('flow_tasks')->whereIn('id', $mismatchedTaskIds)->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('permissions')->updateOrInsert(
            ['slug' => 'documents.link'],
            ['name' => 'Link documents to Jobs and Workflow Phases', 'group' => 'Documents', 'updated_at' => now(), 'created_at' => now()],
        );

        $permissionId = DB::table('permissions')->where('slug', 'documents.link')->value('id');

        DB::table('workspaces')->orderBy('id')->get()->each(function ($workspace) use ($permissionId): void {
            $role = DB::table('roles')
                ->where('workspace_id', $workspace->id)
                ->where(function ($query): void {
                    $query->where('code', 'DOCUMENT_LINKER')
                        ->orWhere('slug', 'document-uploader-workflow-linker');
                })
                ->first();

            if (! $role) {
                $roleId = DB::table('roles')->insertGetId([
                    'workspace_id' => $workspace->id,
                    'name' => 'Document Uploader & Workflow Linker',
                    'slug' => 'document-uploader-workflow-linker',
                    'code' => 'DOCUMENT_LINKER',
                    'description' => 'Uploads only configured Job documents, links them to the active Workflow Phase, and can continue the Job when every gate is complete.',
                    'default_scope' => 'assigned_jobs',
                    'is_system' => true,
                    'is_active' => true,
                    'sensitive_fields' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $roleId = $role->id;
                DB::table('roles')->where('id', $roleId)->update([
                    'name' => 'Document Uploader & Workflow Linker',
                    'slug' => 'document-uploader-workflow-linker',
                    'code' => 'DOCUMENT_LINKER',
                    'description' => 'Uploads only configured Job documents, links them to the active Workflow Phase, and can continue the Job when every gate is complete.',
                    'default_scope' => 'assigned_jobs',
                    'is_system' => true,
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
            }

            $matrix = [
                'dashboard' => ['view'],
                'clients' => ['view'],
                'jobs' => ['view'],
                'tasks' => ['view'],
                'documents' => ['view', 'create', 'link', 'edit_own', 'export'],
            ];

            foreach ($matrix as $module => $actions) {
                DB::table('role_module_access')->updateOrInsert(
                    ['role_id' => $roleId, 'module_code' => $module],
                    ['record_scope' => 'assigned_jobs', 'actions' => json_encode($actions), 'updated_at' => now(), 'created_at' => now()],
                );
            }

            // Existing roles that could upload documents keep working and gain explicit linking permission.
            $workspaceRoleIds = DB::table('roles')
                ->where('workspace_id', $workspace->id)
                ->pluck('id');

            DB::table('role_module_access')
                ->whereIn('role_id', $workspaceRoleIds)
                ->where('module_code', 'documents')
                ->get()
                ->each(function ($access) use ($permissionId): void {
                    $actions = json_decode($access->actions ?: '[]', true) ?: [];
                    if (array_intersect($actions, ['create', 'manage'])) {
                        if (! in_array('link', $actions, true)) {
                            $actions[] = 'link';
                            DB::table('role_module_access')->where('id', $access->id)->update([
                                'actions' => json_encode(array_values(array_unique($actions))),
                                'updated_at' => now(),
                            ]);
                        }
                        if ($permissionId) {
                            DB::table('permission_role')->updateOrInsert([
                                'permission_id' => $permissionId,
                                'role_id' => $access->role_id,
                            ]);
                        }
                    }
                });

            if ($permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        });
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('slug', 'documents.link')->value('id');
        if ($permissionId) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        $roleIds = DB::table('roles')->where('code', 'DOCUMENT_LINKER')->pluck('id');
        DB::table('role_module_access')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();

        Schema::table('flow_documents', function (Blueprint $table): void {
            $table->dropIndex('flow_docs_job_phase_category_idx');
            $table->dropConstrainedForeignId('task_pack_item_id');
            $table->dropConstrainedForeignId('workflow_phase_id');
            $table->dropConstrainedForeignId('linked_by');
            $table->dropColumn('linked_at');
        });
    }
};
