<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->index(['owner_id', 'deleted_at', 'completed_at', 'delivery_date'], 'ft_jobs_owner_open_due_idx');
            $table->index(['workflow_phase_id', 'deleted_at', 'completed_at', 'id'], 'ft_jobs_phase_open_idx');
            $table->index(['attention_requested', 'completed_at', 'updated_at'], 'ft_jobs_attention_updated_idx');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->index(['flow_job_id', 'deleted_at', 'completed_at', 'due_date'], 'ft_tasks_job_open_due_idx');
            $table->index(['workflow_phase_id', 'deleted_at', 'completed_at', 'due_date'], 'ft_tasks_phase_open_due_idx');
        });

        Schema::table('inquiries', function (Blueprint $table): void {
            $table->index(['workspace_id', 'deleted_at', 'completed_at', 'updated_at'], 'ft_inquiries_workspace_open_updated_idx');
            $table->index(['workspace_id', 'owner_id', 'deleted_at', 'completed_at'], 'ft_inquiries_owner_open_idx');
            $table->index(['workspace_id', 'client_id', 'deleted_at', 'updated_at'], 'ft_inquiries_client_updated_idx');
        });

        Schema::table('inquiry_tasks', function (Blueprint $table): void {
            $table->index(['inquiry_id', 'deleted_at', 'completed_at', 'sequence'], 'ft_inquiry_tasks_parent_open_seq_idx');
            $table->index(['assignee_id', 'deleted_at', 'completed_at', 'due_date'], 'ft_inquiry_tasks_assignee_open_due_idx');
        });

        Schema::table('documents', function (Blueprint $table): void {
        });

        Schema::table('inquiry_documents', function (Blueprint $table): void {
            $table->index(['inquiry_id', 'updated_at'], 'ft_inquiry_documents_parent_updated_idx');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->index(['is_active', 'archived_at', 'name'], 'ft_clients_active_archived_name_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropIndex('ft_jobs_owner_open_due_idx');
            $table->dropIndex('ft_jobs_phase_open_idx');
            $table->dropIndex('ft_jobs_attention_updated_idx');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('ft_tasks_job_open_due_idx');
            $table->dropIndex('ft_tasks_phase_open_due_idx');
        });
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropIndex('ft_inquiries_workspace_open_updated_idx');
            $table->dropIndex('ft_inquiries_owner_open_idx');
            $table->dropIndex('ft_inquiries_client_updated_idx');
        });
        Schema::table('inquiry_tasks', function (Blueprint $table): void {
            $table->dropIndex('ft_inquiry_tasks_parent_open_seq_idx');
            $table->dropIndex('ft_inquiry_tasks_assignee_open_due_idx');
        });
        Schema::table('documents', function (Blueprint $table): void {
        });
        Schema::table('inquiry_documents', fn (Blueprint $table) => $table->dropIndex('ft_inquiry_documents_parent_updated_idx'));
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropIndex('ft_clients_active_archived_name_idx');
        });
    }
};
