<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->default(1);
            $table->string('inquiry_number', 40)->unique();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_task_pack_id')->nullable()->constrained('task_packs')->nullOnDelete();
            $table->string('reference_number')->nullable();
            $table->string('client_contact')->nullable();
            $table->date('received_date');
            $table->string('request_source', 40)->nullable();
            $table->string('subject');
            $table->text('requirement_notes')->nullable();
            $table->decimal('target_price', 14, 4)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->date('required_delivery_date')->nullable();
            $table->string('priority', 40)->default('Medium');
            $table->date('initial_follow_up_date')->nullable();
            $table->string('status', 60)->default('In Progress');
            $table->string('result', 30)->nullable();
            $table->string('dead_reason')->nullable();
            $table->text('dead_note')->nullable();
            $table->foreignId('converted_job_id')->nullable()->constrained('flow_jobs')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'status', 'updated_at'], 'inquiries_workspace_status_updated_idx');
            $table->index(['owner_id', 'status', 'required_delivery_date'], 'inquiries_owner_status_due_idx');
            $table->index(['client_id', 'created_at'], 'inquiries_client_created_idx');
        });

        Schema::create('inquiry_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->string('category')->nullable();
            $table->string('item_name');
            $table->decimal('quantity', 14, 2)->default(1);
            $table->string('unit', 30)->default('pcs');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['inquiry_id', 'sort_order']);
        });

        Schema::create('inquiry_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->unsignedBigInteger('source_task_pack_item_id')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('sequence')->default(1);
            $table->date('due_date')->nullable();
            $table->string('status', 60)->default('Waiting');
            $table->boolean('requires_submission')->default(false);
            $table->string('submission_label')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['inquiry_id', 'sequence'], 'inquiry_tasks_sequence_uq');
            $table->index(['assignee_id', 'completed_at', 'due_date'], 'inquiry_tasks_assignee_open_due_idx');
            $table->index(['inquiry_id', 'completed_at', 'sequence'], 'inquiry_tasks_parent_open_sequence_idx');
        });

        Schema::create('inquiry_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('inquiry_task_id')->nullable()->constrained('inquiry_tasks')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
            $table->index(['inquiry_id', 'created_at'], 'inquiry_documents_parent_created_idx');
            $table->index(['inquiry_task_id', 'created_at'], 'inquiry_documents_task_created_idx');
        });

        Schema::create('inquiry_task_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inquiry_task_id')->constrained('inquiry_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['inquiry_task_id', 'created_at'], 'inquiry_task_comments_task_created_idx');
        });

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->foreignId('source_inquiry_id')->nullable()->after('source_workflow_phase_id')->constrained('inquiries')->nullOnDelete();
        });

        Schema::table('flow_notifications', function (Blueprint $table): void {
            $table->foreignId('inquiry_id')->nullable()->after('flow_task_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('inquiry_task_id')->nullable()->after('inquiry_id')->constrained('inquiry_tasks')->cascadeOnDelete();
            $table->index(['user_id', 'inquiry_id', 'read_at'], 'flow_notifications_inquiry_user_idx');
        });

        if (Schema::hasTable('role_module_access')) {
            DB::table('role_module_access')->where('module_code', 'jobs')->orderBy('role_id')->each(function ($row): void {
                $actions = is_string($row->actions) ? (json_decode($row->actions, true) ?: []) : (array) $row->actions;
                if (in_array('create', $actions, true) && !in_array('edit_own', $actions, true)) $actions[] = 'edit_own';

                DB::table('role_module_access')->updateOrInsert(
                    ['role_id' => $row->role_id, 'module_code' => 'inquiries'],
                    [
                        'record_scope' => $row->record_scope,
                        'actions' => json_encode(array_values(array_unique($actions))),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('role_module_access')) {
            DB::table('role_module_access')->where('module_code', 'inquiries')->delete();
        }

        Schema::table('flow_notifications', function (Blueprint $table): void {
            // Drop the foreign keys before the compound index: MySQL can choose
            // that index to satisfy a foreign-key requirement.
            $table->dropForeign(['inquiry_task_id']);
            $table->dropForeign(['inquiry_id']);
            $table->dropIndex('flow_notifications_inquiry_user_idx');
            $table->dropColumn(['inquiry_task_id', 'inquiry_id']);
        });

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_inquiry_id');
        });

        Schema::dropIfExists('inquiry_task_comments');
        Schema::dropIfExists('inquiry_documents');
        Schema::dropIfExists('inquiry_tasks');
        Schema::dropIfExists('inquiry_items');
        Schema::dropIfExists('inquiries');
    }
};
