<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_notifications') && !Schema::hasColumn('flow_notifications', 'flow_task_id')) {
            Schema::table('flow_notifications', function (Blueprint $table) {
                $table->foreignId('flow_task_id')->nullable()->after('flow_job_id')->constrained('tasks')->cascadeOnDelete();
                $table->index(['user_id', 'flow_task_id', 'read_at'], 'ft_notifications_user_task_read_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('flow_notifications') && Schema::hasColumn('flow_notifications', 'flow_task_id')) {
            Schema::table('flow_notifications', function (Blueprint $table) {
                $table->dropIndex('ft_notifications_user_task_read_idx');
                $table->dropConstrainedForeignId('flow_task_id');
            });
        }
    }
};
