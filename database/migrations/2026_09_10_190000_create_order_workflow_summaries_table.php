<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_workflow_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_id')->unique()->constrained('flow_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('workflow_id')->nullable();
            $table->string('workflow_name')->nullable();
            $table->unsignedBigInteger('workflow_phase_id')->nullable();
            $table->string('current_phase_name')->nullable();
            $table->string('current_phase_short_name', 120)->nullable();
            $table->unsignedSmallInteger('current_phase_sequence')->default(1);
            $table->unsignedSmallInteger('stage_count')->default(0);
            $table->unsignedSmallInteger('current_task_count')->default(0);
            $table->unsignedSmallInteger('current_applicable_task_count')->default(0);
            $table->unsignedSmallInteger('current_completed_task_count')->default(0);
            $table->unsignedBigInteger('next_task_id')->nullable();
            $table->string('next_task_title')->nullable();
            $table->unsignedBigInteger('next_task_assignee_id')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('order_status', 80)->nullable();
            $table->boolean('is_on_hold')->default(false);
            $table->boolean('is_stale')->default(false);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'is_stale'], 'order_wf_summary_workflow_stale_idx');
            $table->index(['workflow_phase_id', 'is_stale'], 'order_wf_summary_phase_stale_idx');
            $table->index('next_task_id', 'order_wf_summary_next_task_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_workflow_summaries');
    }
};
