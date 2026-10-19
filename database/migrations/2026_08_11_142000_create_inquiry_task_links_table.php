<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inquiry_task_links')) {
            return;
        }

        Schema::create('inquiry_task_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_task_id')->constrained('inquiry_tasks')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('url');
            $table->timestamps();
            $table->index(['inquiry_task_id', 'created_at'], 'inquiry_task_links_task_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_task_links');
    }
};
