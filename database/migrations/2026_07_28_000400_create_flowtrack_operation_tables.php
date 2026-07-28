<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('workflow_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_phase_id')->nullable()->constrained('workflow_phases')->nullOnDelete();
            $table->foreignId('started_phase_id')->nullable()->constrained('workflow_phases')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('order_code', 40)->nullable()->unique();
            $table->string('title');
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('quoted_amount', 15, 2)->default(0);
            $table->date('delivery_date')->nullable();
            $table->string('status', 40)->default('new');
            $table->string('health', 40)->default('on_track');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('next_action')->nullable();
            $table->string('milestone')->nullable();
            $table->boolean('attention_required')->default(false);
            $table->string('start_handling', 50)->default('normal');
            $table->text('start_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'status', 'health']);
            $table->index(['delivery_date', 'progress']);
        });

        Schema::create('flow_job_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
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
            $table->foreignId('workflow_phase_id')->constrained()->restrictOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30);
            $table->text('notes')->nullable();
            $table->timestamp('entered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['flow_job_id', 'workflow_phase_id']);
        });

        Schema::create('flow_phase_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('workflow_phase_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('approved');
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['flow_job_id', 'workflow_phase_id']);
        });

        Schema::create('flow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('workflow_phase_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_pack_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('flow_tasks')->cascadeOnDelete();
            $table->foreignId('dependency_id')->nullable()->constrained('flow_tasks')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('task_status_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('start_rule')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->boolean('attention_required')->default(false);
            $table->text('blocker_reason')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['assignee_id', 'due_date']);
        });

        Schema::create('flow_task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_task_id')->constrained('flow_tasks')->cascadeOnDelete();
            $table->string('label');
            $table->boolean('is_completed')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('flow_task_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_task_id')->constrained('flow_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('flow_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('document_category_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->boolean('is_final')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flow_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_document_id')->constrained('flow_documents')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['flow_document_id', 'version']);
        });

        Schema::create('flow_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('event', 60);
            $table->string('description');
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('flow_activities');
        Schema::dropIfExists('flow_document_versions');
        Schema::dropIfExists('flow_documents');
        Schema::dropIfExists('flow_task_comments');
        Schema::dropIfExists('flow_task_checklist_items');
        Schema::dropIfExists('flow_tasks');
        Schema::dropIfExists('flow_phase_approvals');
        Schema::dropIfExists('flow_job_phase_histories');
        Schema::dropIfExists('flow_job_members');
        Schema::dropIfExists('flow_jobs');
    }
};
