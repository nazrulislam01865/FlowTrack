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
                // Board Task Pack association checks start with the signed-in
                // assignee and need the related Job id immediately. Keeping the
                // soft-delete marker in the prefix prevents deleted assignments
                // from making a Job visible.
                $table->index(
                    ['assignee_id', 'deleted_at', 'flow_job_id'],
                    'ft_tasks_board_assignee_job_idx',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', fn (Blueprint $table) =>
                $table->dropIndex('ft_tasks_board_assignee_job_idx')
            );
        }
    }
};
