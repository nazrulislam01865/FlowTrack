<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->index(['deleted_at', 'completed_at', 'status', 'delivery_date'], 'ft_jobs_active_delivery_idx');
                $table->index(['owner_id', 'deleted_at', 'completed_at', 'status'], 'ft_jobs_owner_active_idx');
                $table->index(['client_id', 'deleted_at', 'completed_at', 'status'], 'ft_jobs_client_active_idx');
                $table->index(['workflow_id', 'workflow_phase_id', 'deleted_at', 'completed_at'], 'ft_jobs_workflow_phase_active_idx');
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->index(['deleted_at', 'completed_at', 'due_date'], 'ft_tasks_active_due_idx');
                $table->index(['assignee_id', 'deleted_at', 'completed_at', 'due_date'], 'ft_tasks_assignee_active_due_idx');
                $table->index(['flow_job_id', 'deleted_at', 'completed_at', 'status', 'due_date'], 'ft_tasks_job_active_status_due_idx');
                $table->index(['flow_job_id', 'workflow_phase_id', 'task_pack_task_id', 'deleted_at'], 'ft_tasks_job_phase_template_idx');
            });
        }

        if (Schema::hasTable('flow_job_members')) {
            Schema::table('flow_job_members', function (Blueprint $table): void {
                $table->index(['user_id', 'flow_job_id'], 'ft_job_members_user_job_idx');
            });
        }

        if (Schema::hasTable('flow_notifications')) {
            Schema::table('flow_notifications', function (Blueprint $table): void {
                $table->index(['user_id', 'read_at', 'created_at'], 'ft_notifications_user_read_created_idx');
            });
        }

        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->index(['task_id', 'category', 'name', 'version'], 'ft_documents_task_category_version_idx');
            });
        }

        if (Schema::hasTable('activities')) {
            Schema::table('activities', function (Blueprint $table): void {
                $table->index(['subject_type', 'subject_id', 'created_at'], 'ft_activities_subject_created_idx');
            });
        }

        if (Schema::hasTable('master_records')) {
            Schema::table('master_records', function (Blueprint $table): void {
                $table->index(['workspace_id', 'type', 'status', 'sort_order'], 'ft_master_active_sort_idx');
            });
        }

        if (Schema::hasTable('workflow_phases')) {
            Schema::table('workflow_phases', function (Blueprint $table): void {
                $table->index(['workflow_id', 'is_active', 'sequence'], 'ft_workflow_phases_active_sequence_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_phases')) {
            Schema::table('workflow_phases', fn (Blueprint $table) => $table->dropIndex('ft_workflow_phases_active_sequence_idx'));
        }
        if (Schema::hasTable('master_records')) {
            Schema::table('master_records', fn (Blueprint $table) => $table->dropIndex('ft_master_active_sort_idx'));
        }
        if (Schema::hasTable('activities')) {
            Schema::table('activities', fn (Blueprint $table) => $table->dropIndex('ft_activities_subject_created_idx'));
        }
        if (Schema::hasTable('documents')) {
            Schema::table('documents', fn (Blueprint $table) => $table->dropIndex('ft_documents_task_category_version_idx'));
        }
        if (Schema::hasTable('flow_notifications')) {
            Schema::table('flow_notifications', fn (Blueprint $table) => $table->dropIndex('ft_notifications_user_read_created_idx'));
        }
        if (Schema::hasTable('flow_job_members')) {
            Schema::table('flow_job_members', fn (Blueprint $table) => $table->dropIndex('ft_job_members_user_job_idx'));
        }
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                $table->dropIndex('ft_tasks_active_due_idx');
                $table->dropIndex('ft_tasks_assignee_active_due_idx');
                $table->dropIndex('ft_tasks_job_active_status_due_idx');
                $table->dropIndex('ft_tasks_job_phase_template_idx');
            });
        }
        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->dropIndex('ft_jobs_active_delivery_idx');
                $table->dropIndex('ft_jobs_owner_active_idx');
                $table->dropIndex('ft_jobs_client_active_idx');
                $table->dropIndex('ft_jobs_workflow_phase_active_idx');
            });
        }
    }
};
