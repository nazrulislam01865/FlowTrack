<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_job_members', function (Blueprint $table): void {
            $table->index(['user_id', 'flow_job_id'], 'ft_job_members_user_job_idx');
        });

        Schema::table('flow_tasks', function (Blueprint $table): void {
            $table->index(['flow_job_id', 'workflow_phase_id', 'sort_order', 'id'], 'ft_tasks_job_phase_order_idx');
            $table->index(['assignee_id', 'completed_at', 'due_date'], 'ft_tasks_assignee_done_due_idx');
            $table->index(['flow_job_id', 'completed_at', 'due_date'], 'ft_tasks_job_done_due_idx');
        });

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->index(['workspace_id', 'status', 'id'], 'ft_jobs_workspace_status_id_idx');
            $table->index(['workspace_id', 'owner_id'], 'ft_jobs_workspace_owner_idx');
            $table->index(['workspace_id', 'coordinator_id'], 'ft_jobs_workspace_coord_idx');
            $table->index(['workspace_id', 'client_id', 'id'], 'ft_jobs_workspace_client_id_idx');
        });

        Schema::table('flow_documents', function (Blueprint $table): void {
            $table->index(['workspace_id', 'flow_job_id', 'id'], 'ft_documents_workspace_job_id_idx');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'ft_notifications_recipient_read_idx');
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'ft_notifications_recipient_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('ft_notifications_recipient_read_idx');
            $table->dropIndex('ft_notifications_recipient_created_idx');
        });

        Schema::table('flow_documents', function (Blueprint $table): void {
            $table->dropIndex('ft_documents_workspace_job_id_idx');
        });

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropIndex('ft_jobs_workspace_status_id_idx');
            $table->dropIndex('ft_jobs_workspace_owner_idx');
            $table->dropIndex('ft_jobs_workspace_coord_idx');
            $table->dropIndex('ft_jobs_workspace_client_id_idx');
        });

        Schema::table('flow_tasks', function (Blueprint $table): void {
            $table->dropIndex('ft_tasks_job_phase_order_idx');
            $table->dropIndex('ft_tasks_assignee_done_due_idx');
            $table->dropIndex('ft_tasks_job_done_due_idx');
        });

        Schema::table('flow_job_members', function (Blueprint $table): void {
            $table->dropIndex('ft_job_members_user_job_idx');
        });
    }
};
