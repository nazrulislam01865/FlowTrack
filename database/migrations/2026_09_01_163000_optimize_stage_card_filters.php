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
                // Orders stage-card filtering starts from a canonical source
                // phase and then narrows to open rows ordered newest-first.
                $table->index(
                    ['source_workflow_phase_id', 'deleted_at', 'completed_at', 'created_at', 'id'],
                    'ft_jobs_source_phase_open_created_idx',
                );
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                // My Tasks stage cards always begin with the signed-in assignee
                // and canonical current workflow phase. Keep that hot path
                // covered before structural active-task checks run.
                $table->index(
                    ['assignee_id', 'workflow_phase_id', 'deleted_at', 'completed_at', 'flow_job_id'],
                    'ft_tasks_assignee_phase_open_job_idx',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', fn (Blueprint $table) =>
                $table->dropIndex('ft_tasks_assignee_phase_open_job_idx')
            );
        }

        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', fn (Blueprint $table) =>
                $table->dropIndex('ft_jobs_source_phase_open_created_idx')
            );
        }
    }
};
