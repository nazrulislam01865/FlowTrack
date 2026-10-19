<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_artwork_reminder_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained('workspaces')->cascadeOnDelete();
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('supplier_artwork_reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('reason', 180)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('tracking_id', 80)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'supplier_id'], 'supplier_artwork_reminder_unique_send');
            $table->index(['workspace_id', 'id'], 'supplier_artwork_reminder_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_artwork_reminder_deliveries');
        Schema::dropIfExists('supplier_artwork_reminder_settings');
    }
};
