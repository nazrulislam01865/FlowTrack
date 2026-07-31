<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('flow_tasks', 'setup_assignee_id')) {
                $table->foreignId('setup_assignee_id')
                    ->nullable()
                    ->after('assignee_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('flow_tasks', 'assignee_overridden_at')) {
                $table->timestamp('assignee_overridden_at')->nullable()->after('setup_assignee_id');
            }
            if (! Schema::hasColumn('flow_tasks', 'assignee_overridden_by')) {
                $table->foreignId('assignee_overridden_by')
                    ->nullable()
                    ->after('assignee_overridden_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        // Preserve all existing assignments. A generated Task whose current
        // assignee differs from Task Setup is treated as an existing override.
        DB::table('flow_tasks')
            ->join('flow_jobs', 'flow_jobs.id', '=', 'flow_tasks.flow_job_id')
            ->leftJoin('task_pack_items', 'task_pack_items.id', '=', 'flow_tasks.task_pack_item_id')
            ->where(function ($query): void {
                $query->whereNotNull('flow_tasks.task_pack_item_id')
                    ->orWhere('flow_tasks.document_requirement_source', 'workflow_phase');
            })
            ->select([
                'flow_tasks.id',
                'flow_tasks.assignee_id',
                'flow_tasks.updated_at',
                'flow_tasks.task_pack_item_id',
                'flow_jobs.workspace_id',
                'flow_jobs.owner_id',
                'flow_jobs.coordinator_id',
                'task_pack_items.default_assignee_id',
                'task_pack_items.default_department_id',
            ])
            ->orderBy('flow_tasks.id')
            ->chunkById(200, function ($tasks): void {
                foreach ($tasks as $task) {
                    $resolved = null;

                    if ($task->default_assignee_id) {
                        $resolved = DB::table('workspace_memberships')
                            ->where('workspace_id', $task->workspace_id)
                            ->where('user_id', $task->default_assignee_id)
                            ->where('status', 'active')
                            ->value('user_id');
                    }

                    if (! $resolved && $task->default_department_id) {
                        $resolved = DB::table('workspace_memberships')
                            ->where('workspace_id', $task->workspace_id)
                            ->where('department_id', $task->default_department_id)
                            ->where('status', 'active')
                            ->orderBy('id')
                            ->value('user_id');
                    }

                    $resolved = $resolved ?: $task->coordinator_id ?: $task->owner_id;
                    $isOverride = (int) ($task->assignee_id ?? 0) !== (int) ($resolved ?? 0);

                    DB::table('flow_tasks')->where('id', $task->id)->update([
                        'setup_assignee_id' => $resolved,
                        'assignee_overridden_at' => $isOverride ? ($task->updated_at ?: now()) : null,
                        'assignee_overridden_by' => null,
                    ]);
                }
            }, 'flow_tasks.id', 'id');
    }

    public function down(): void
    {
        Schema::table('flow_tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_tasks', 'assignee_overridden_by')) {
                $table->dropConstrainedForeignId('assignee_overridden_by');
            }
            if (Schema::hasColumn('flow_tasks', 'assignee_overridden_at')) {
                $table->dropColumn('assignee_overridden_at');
            }
            if (Schema::hasColumn('flow_tasks', 'setup_assignee_id')) {
                $table->dropConstrainedForeignId('setup_assignee_id');
            }
        });
    }
};
