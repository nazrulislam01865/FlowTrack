<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These support tables mirror the useful board-oriented structures in the
        // supplied FlowTrack SQL while preserving this project's existing core tables.
        Schema::create('flow_job_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->string('product_name')->nullable();
            $table->string('category_name')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['flow_job_id', 'sort_order']);
        });

        Schema::create('flow_job_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 30)->default('member');
            $table->boolean('can_manage_tasks')->default(false);
            $table->boolean('can_upload_documents')->default(true);
            $table->boolean('can_view_financials')->default(false);
            $table->timestamps();
            $table->unique(['flow_job_id', 'user_id']);
        });

        Schema::create('flow_job_phase_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('workflow_phase_id')->constrained('workflow_phases')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('phase_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable();
            $table->string('health_override', 40)->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['flow_job_id', 'workflow_phase_id', 'entered_at'], 'fjph_job_phase_entered_idx');
        });

        Schema::create('flow_task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('label');
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['flow_task_id', 'sort_order']);
        });

        Schema::create('flow_task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['flow_task_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_task_comments');
        Schema::dropIfExists('flow_task_checklist_items');
        Schema::dropIfExists('flow_job_phase_histories');
        Schema::dropIfExists('flow_job_members');
        Schema::dropIfExists('flow_job_items');
    }
};
