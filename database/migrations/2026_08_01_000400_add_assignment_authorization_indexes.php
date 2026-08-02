<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'flow_tasks',
            ['assignee_id', 'flow_job_id', 'deleted_at'],
            'ft_tasks_effective_assignee_job_idx',
        );
        $this->addIndexIfMissing(
            'flow_tasks',
            ['setup_assignee_id', 'assignee_overridden_at', 'flow_job_id', 'deleted_at'],
            'ft_tasks_setup_assignee_job_idx',
        );
        $this->addIndexIfMissing(
            'workspace_memberships',
            ['workspace_id', 'department_id', 'status', 'user_id'],
            'ft_membership_workspace_dept_active_idx',
        );
        $this->addIndexIfMissing(
            'workspace_memberships',
            ['role_id', 'status', 'workspace_id', 'user_id'],
            'ft_membership_role_active_workspace_idx',
        );
        $this->addIndexIfMissing(
            'flow_documents',
            ['flow_task_id', 'document_category_id', 'deleted_at'],
            'ft_documents_task_category_active_idx',
        );
    }

    public function down(): void
    {
        $this->dropIndexIfPresent('flow_documents', 'ft_documents_task_category_active_idx');
        $this->dropIndexIfPresent('workspace_memberships', 'ft_membership_workspace_dept_active_idx');
        $this->dropIndexIfPresent('workspace_memberships', 'ft_membership_role_active_workspace_idx');
        $this->dropIndexIfPresent('flow_tasks', 'ft_tasks_effective_assignee_job_idx');
        $this->dropIndexIfPresent('flow_tasks', 'ft_tasks_setup_assignee_job_idx');
    }

    /** @param list<string> $columns */
    private function addIndexIfMissing(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName)
            || ! Schema::hasColumns($tableName, $columns)
            || Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndexIfPresent(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }
};
