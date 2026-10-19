<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('task_pack_items')
            || !Schema::hasTable('tasks')
            || !Schema::hasColumn('tasks', 'task_pack_task_id')
            || !Schema::hasColumn('tasks', 'assignee_id')
        ) {
            return;
        }

        $hasSetupAssignee = Schema::hasColumn('tasks', 'setup_assignee_id');
        $hasDepartments = Schema::hasTable('departments');
        $hasMasterRecords = Schema::hasTable('master_records');

        DB::table('task_pack_items')->orderBy('id')->each(function ($item) use ($hasSetupAssignee, $hasDepartments, $hasMasterRecords): void {
            $desiredAssigneeId = $item->default_assignee_id ? (int) $item->default_assignee_id : null;

            if (!$desiredAssigneeId && $item->default_department_id && $hasDepartments && $hasMasterRecords) {
                $departmentCode = DB::table('master_records')
                    ->where('id', $item->default_department_id)
                    ->where('type', 'department')
                    ->value('code');

                if ($departmentCode) {
                    $legacyDepartmentId = DB::table('departments')->where('code', $departmentCode)->value('id');
                    if ($legacyDepartmentId) {
                        $desiredAssigneeId = DB::table('users')
                            ->where('is_active', true)
                            ->where('department_id', $legacyDepartmentId)
                            ->orderBy('id')
                            ->value('id');
                        $desiredAssigneeId = $desiredAssigneeId ? (int) $desiredAssigneeId : null;
                    }
                }
            }

            if (!$desiredAssigneeId) return;

            DB::table('tasks')
                ->where('task_pack_task_id', $item->id)
                ->orderBy('id')
                ->each(function ($task) use ($desiredAssigneeId, $hasSetupAssignee): void {
                    $storedSetupId = $hasSetupAssignee && $task->setup_assignee_id
                        ? (int) $task->setup_assignee_id
                        : null;

                    // Only repair generated tasks that are currently
                    // unassigned or still follow their previous Task Pack
                    // assignment. A deliberate manual reassignment is kept.
                    $followsTaskPack = !$task->assignee_id
                        || ($storedSetupId && (int) $task->assignee_id === $storedSetupId);

                    if (!$followsTaskPack) return;

                    $changes = [
                        'assignee_id' => $desiredAssigneeId,
                        'updated_at' => now(),
                    ];
                    if ($hasSetupAssignee) $changes['setup_assignee_id'] = $desiredAssigneeId;

                    DB::table('tasks')->where('id', $task->id)->update($changes);

                    if (Schema::hasTable('flow_job_members')) {
                        DB::table('flow_job_members')->updateOrInsert(
                            ['flow_job_id' => $task->flow_job_id, 'user_id' => $desiredAssigneeId],
                            [
                                'access_level' => 'member',
                                'can_manage_tasks' => false,
                                'can_upload_documents' => true,
                                'can_view_financials' => false,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                });
        });
    }

    public function down(): void
    {
        // Do not undo corrected assignments; doing so could remove legitimate
        // work ownership created after the migration ran.
    }
};
