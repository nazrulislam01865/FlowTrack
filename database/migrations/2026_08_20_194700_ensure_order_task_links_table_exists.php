<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('task_links')) {
            return;
        }

        Schema::create('task_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('url');
            $table->timestamps();
            $table->index(['task_id', 'created_at'], 'task_links_task_created_idx');
        });
    }

    public function down(): void
    {
        // This is a compatibility/repair migration. Do not remove an existing
        // task_links table on rollback because it may have been created by the
        // original Order task-resource migration and may contain user data.
    }
};
