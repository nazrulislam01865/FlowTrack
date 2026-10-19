<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_links')) {
            Schema::create('task_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('url');
                $table->timestamps();
                $table->index(['task_id', 'created_at'], 'task_links_task_created_idx');
            });
        }

        if (Schema::hasTable('documents') && ! Schema::hasColumn('documents', 'note')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->text('note')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'note')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->dropColumn('note');
            });
        }

        Schema::dropIfExists('task_links');
    }
};
