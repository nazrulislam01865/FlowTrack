<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $table): void {
                // My Work starts from the signed-in user's open assignments and
                // repeatedly ranks them by status/due date without loading all rows.
                $table->index(
                    ['assignee_id', 'deleted_at', 'completed_at', 'status', 'due_date'],
                    'ft_tasks_my_work_personal_idx',
                );
            });
        }

        if (Schema::hasTable('flow_notifications') && Schema::hasColumn('flow_notifications', 'flow_task_id')) {
            Schema::table('flow_notifications', function (Blueprint $table): void {
                // Mentions are part of personal work. This supports the EXISTS
                // lookup without scanning another user's notification history.
                $table->index(
                    ['user_id', 'type', 'flow_task_id'],
                    'ft_notifications_my_work_mentions_idx',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('flow_notifications') && Schema::hasColumn('flow_notifications', 'flow_task_id')) {
            Schema::table('flow_notifications', fn (Blueprint $table) =>
                $table->dropIndex('ft_notifications_my_work_mentions_idx')
            );
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', fn (Blueprint $table) =>
                $table->dropIndex('ft_tasks_my_work_personal_idx')
            );
        }
    }
};
