<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_tasks') || ! Schema::hasColumn('flow_tasks', 'setup_assignee_id')) {
            return;
        }

        DB::table('flow_tasks')
            ->join('flow_jobs', 'flow_jobs.id', '=', 'flow_tasks.flow_job_id')
            ->join('task_pack_items', 'task_pack_items.id', '=', 'flow_tasks.task_pack_item_id')
            ->select([
                'flow_tasks.id',
                'flow_tasks.assignee_id',
                'flow_tasks.setup_assignee_id',
                'flow_tasks.assignee_overridden_at',
                'flow_jobs.workspace_id',
                'flow_jobs.owner_id',
                'flow_jobs.coordinator_id',
                'task_pack_items.default_assignee_id',
                'task_pack_items.default_department_id',
            ])
            ->whereNull('flow_tasks.deleted_at')
            ->orderBy('flow_tasks.id')
            ->chunkById(200, function ($tasks): void {
                foreach ($tasks as $task) {
                    $resolved = $this->resolveSetupAssignee($task);
                    $hasManualOverride = $task->assignee_overridden_at !== null
                        && (int) ($task->assignee_id ?? 0) !== (int) ($task->setup_assignee_id ?? 0);

                    DB::table('flow_tasks')->where('id', $task->id)->update([
                        'setup_assignee_id' => $resolved,
                        'assignee_id' => $hasManualOverride ? $task->assignee_id : $resolved,
                        'assignee_overridden_at' => $hasManualOverride ? $task->assignee_overridden_at : null,
                        'assignee_overridden_by' => $hasManualOverride ? DB::raw('assignee_overridden_by') : null,
                    ]);
                }
            }, 'flow_tasks.id', 'id');

        DB::table('flow_tasks')
            ->join('flow_jobs', 'flow_jobs.id', '=', 'flow_tasks.flow_job_id')
            ->select([
                'flow_tasks.id',
                'flow_tasks.assignee_id',
                'flow_tasks.setup_assignee_id',
                'flow_tasks.assignee_overridden_at',
                'flow_jobs.owner_id',
                'flow_jobs.coordinator_id',
            ])
            ->whereNull('flow_tasks.task_pack_item_id')
            ->where('flow_tasks.document_requirement_source', 'workflow_phase')
            ->whereNull('flow_tasks.deleted_at')
            ->orderBy('flow_tasks.id')
            ->chunkById(200, function ($tasks): void {
                foreach ($tasks as $task) {
                    $resolved = $task->coordinator_id ?: $task->owner_id;
                    $hasManualOverride = $task->assignee_overridden_at !== null
                        && (int) ($task->assignee_id ?? 0) !== (int) ($task->setup_assignee_id ?? 0);

                    DB::table('flow_tasks')->where('id', $task->id)->update([
                        'setup_assignee_id' => $resolved,
                        'assignee_id' => $hasManualOverride ? $task->assignee_id : $resolved,
                        'assignee_overridden_at' => $hasManualOverride ? $task->assignee_overridden_at : null,
                        'assignee_overridden_by' => $hasManualOverride ? DB::raw('assignee_overridden_by') : null,
                    ]);
                }
            }, 'flow_tasks.id', 'id');
    }

    public function down(): void
    {
        // Data repair only; no schema to roll back.
    }

    private function resolveSetupAssignee(object $task): ?int
    {
        if ($task->default_assignee_id) {
            $activeAssignee = DB::table('workspace_memberships')
                ->where('workspace_id', $task->workspace_id)
                ->where('user_id', $task->default_assignee_id)
                ->where('status', 'active')
                ->value('user_id');

            if ($activeAssignee) {
                return (int) $activeAssignee;
            }
        }

        if ($task->default_department_id) {
            $departmentAssignee = DB::table('workspace_memberships')
                ->where('workspace_id', $task->workspace_id)
                ->where('department_id', $task->default_department_id)
                ->where('status', 'active')
                ->orderBy('id')
                ->value('user_id');

            if ($departmentAssignee) {
                return (int) $departmentAssignee;
            }
        }

        return $task->coordinator_id ?: $task->owner_id;
    }
};
