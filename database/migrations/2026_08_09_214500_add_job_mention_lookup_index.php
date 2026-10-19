<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_notifications') && Schema::hasColumn('flow_notifications', 'flow_job_id')) {
            Schema::table('flow_notifications', function (Blueprint $table): void {
                // My Work resolves both task-level and Order-level mentions.
                $table->index(
                    ['user_id', 'type', 'flow_job_id'],
                    'ft_notifications_my_work_job_mentions_idx',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('flow_notifications') && Schema::hasColumn('flow_notifications', 'flow_job_id')) {
            Schema::table('flow_notifications', fn (Blueprint $table) =>
                $table->dropIndex('ft_notifications_my_work_job_mentions_idx')
            );
        }
    }
};
