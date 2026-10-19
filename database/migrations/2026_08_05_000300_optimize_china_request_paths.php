<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', fn (Blueprint $table) =>
                $table->index(['deleted_at', 'completed_at', 'delivery_date'], 'ft_jobs_open_delivery_idx')
            );
        }
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', fn (Blueprint $table) =>
                $table->index(['flow_job_id', 'workflow_phase_id', 'deleted_at', 'completed_at', 'due_date'], 'ft_tasks_job_phase_open_due_idx')
            );
        }
        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->index(['is_active', 'country', 'name'], 'ft_clients_active_country_name_idx');
                $table->index(['account_manager_id', 'is_active', 'name'], 'ft_clients_manager_active_name_idx');
            });
        }
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->index(['client_id', 'updated_at'], 'ft_docs_client_updated_idx');
                $table->index(['flow_job_id', 'updated_at'], 'ft_docs_job_updated_idx');
                $table->index(['task_id', 'updated_at'], 'ft_docs_task_updated_idx');
            });
        }
        if (Schema::hasTable('master_records')) {
            Schema::table('master_records', fn (Blueprint $table) =>
                $table->index(['workspace_id', 'type', 'status', 'deleted_at', 'sort_order'], 'ft_master_active_deleted_sort_idx')
            );
        }
        if (Schema::hasTable('workflow_phases') && Schema::hasColumn('workflow_phases', 'workflow_template_id')) {
            Schema::table('workflow_phases', fn (Blueprint $table) =>
                $table->index(['workflow_template_id', 'is_active', 'sequence'], 'ft_workflow_template_active_sequence_idx')
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workflow_phases') && Schema::hasColumn('workflow_phases', 'workflow_template_id')) {
            Schema::table('workflow_phases', fn (Blueprint $table) => $table->dropIndex('ft_workflow_template_active_sequence_idx'));
        }
        if (Schema::hasTable('master_records')) {
            Schema::table('master_records', fn (Blueprint $table) => $table->dropIndex('ft_master_active_deleted_sort_idx'));
        }
        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->dropIndex('ft_docs_client_updated_idx');
                $table->dropIndex('ft_docs_job_updated_idx');
                $table->dropIndex('ft_docs_task_updated_idx');
            });
        }
        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->dropIndex('ft_clients_active_country_name_idx');
                $table->dropIndex('ft_clients_manager_active_name_idx');
            });
        }
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', fn (Blueprint $table) => $table->dropIndex('ft_tasks_job_phase_open_due_idx'));
        }
        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', fn (Blueprint $table) => $table->dropIndex('ft_jobs_open_delivery_idx'));
        }
    }
};
