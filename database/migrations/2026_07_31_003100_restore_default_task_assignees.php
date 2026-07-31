<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_tasks')
            || ! Schema::hasTable('flow_jobs')
            || ! Schema::hasColumn('flow_tasks', 'setup_assignee_id')
            || ! Schema::hasColumn('flow_tasks', 'assignee_overridden_at')) {
            return;
        }

        $this->repairTaskPackGeneratedTasks();
        $this->repairWorkflowDocumentTasks();
        $this->applyStoredSetupDefaults();
    }

    public function down(): void
    {
        // Data repair only. Nothing to roll back safely without losing
        // assignments made by users after deployment.
    }

    private function repairTaskPackGeneratedTasks(): void
    {
        if (! Schema::hasTable('task_pack_items')) {
            return;
        }

        DB::table('flow_tasks')
            ->join('flow_jobs', 'flow_jobs.id', '=', 'flow_tasks.flow_job_id')
            ->join('task_pack_items', 'task_pack_items.id', '=', 'flow_tasks.task_pack_item_id')
            ->whereNull('flow_tasks.deleted_at')
            ->whereNull('flow_tasks.assignee_overridden_at')
            ->select([
                'flow_tasks.id',
                'flow_jobs.workspace_id',
                'flow_jobs.owner_id',
                'flow_jobs.coordinator_id',
                'task_pack_items.default_assignee_id',
                'task_pack_items.default_department_id',
            ])
            ->orderBy('flow_tasks.id')
            ->chunkById(200, function ($tasks): void {
                foreach ($tasks as $task) {
                    $resolved = $this->resolveDefaultAssignee($task);
                    DB::table('flow_tasks')->where('id', $task->id)->update([
                        'setup_assignee_id' => $resolved,
                        'assignee_id' => $resolved,
                        'assignee_overridden_at' => null,
                        'assignee_overridden_by' => null,
                        'updated_at' => now(),
                    ]);
                }
            }, 'flow_tasks.id', 'id');
    }

    private function repairWorkflowDocumentTasks(): void
    {
        DB::table('flow_tasks')
            ->join('flow_jobs', 'flow_jobs.id', '=', 'flow_tasks.flow_job_id')
            ->whereNull('flow_tasks.deleted_at')
            ->whereNull('flow_tasks.task_pack_item_id')
            ->where('flow_tasks.document_requirement_source', 'workflow_phase')
            ->whereNull('flow_tasks.assignee_overridden_at')
            ->select([
                'flow_tasks.id',
                'flow_jobs.owner_id',
                'flow_jobs.coordinator_id',
            ])
            ->orderBy('flow_tasks.id')
            ->chunkById(200, function ($tasks): void {
                foreach ($tasks as $task) {
                    $resolved = $task->coordinator_id ?: $task->owner_id;
                    DB::table('flow_tasks')->where('id', $task->id)->update([
                        'setup_assignee_id' => $resolved,
                        'assignee_id' => $resolved,
                        'assignee_overridden_at' => null,
                        'assignee_overridden_by' => null,
                        'updated_at' => now(),
                    ]);
                }
            }, 'flow_tasks.id', 'id');
    }

    private function applyStoredSetupDefaults(): void
    {
        DB::table('flow_tasks')
            ->whereNull('deleted_at')
            ->whereNull('assignee_overridden_at')
            ->whereNotNull('setup_assignee_id')
            ->where(function ($query): void {
                $query->whereNull('assignee_id')
                    ->orWhereColumn('assignee_id', '!=', 'setup_assignee_id');
            })
            ->update([
                'assignee_id' => DB::raw('setup_assignee_id'),
                'updated_at' => now(),
            ]);
    }

    private function resolveDefaultAssignee(object $task): ?int
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
