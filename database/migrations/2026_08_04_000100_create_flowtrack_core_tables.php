<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('role_id')->constrained()->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_super_admin');
            $table->string('locale', 10)->default('en')->after('is_active');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('module');
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('country')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('outstanding_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });

        Schema::create('task_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('task_pack_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_pack_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->boolean('is_required')->default(true);
            $table->foreignId('default_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamps();
            $table->unique(['task_pack_id', 'sequence']);
        });

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('workflow_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('name');
            $table->string('short_name');
            $table->boolean('allow_job_start')->default(false);
            $table->boolean('can_skip')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->string('required_document')->nullable();
            $table->string('entry_rule')->nullable();
            $table->string('exit_rule')->nullable();
            $table->timestamps();
            $table->unique(['workflow_id', 'sequence']);
        });

        Schema::create('master_values', function (Blueprint $table) {
            $table->id();
            $table->string('group_key', 60);
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('master_values')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['group_key', 'code']);
            $table->index(['group_key', 'is_active']);
        });

        Schema::create('flow_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_number')->unique();
            $table->string('order_number')->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('workflow_id')->constrained()->restrictOnDelete();
            $table->foreignId('workflow_phase_id')->constrained('workflow_phases')->restrictOnDelete();
            $table->foreignId('started_from_phase_id')->nullable()->constrained('workflow_phases')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('product')->nullable();
            $table->string('category')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('commercial_value', 14, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status')->default('New');
            $table->string('health')->default('On Track');
            $table->string('priority')->default('Medium');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('delivery_date')->nullable();
            $table->text('description')->nullable();
            $table->string('next_action')->nullable();
            $table->string('start_handling')->nullable();
            $table->text('start_reason')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workflow_phase_id', 'status']);
            $table->index(['coordinator_id', 'status']);
            $table->index(['client_id', 'created_at']);
            $table->index(['needs_attention', 'completed_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_number')->unique();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('workflow_phase_id')->nullable()->constrained('workflow_phases')->nullOnDelete();
            $table->foreignId('task_pack_task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('status')->default('Ready');
            $table->string('priority')->default('Medium');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->date('due_date')->nullable();
            $table->boolean('needs_attention')->default(false);
            $table->text('attention_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['assignee_id', 'status', 'due_date']);
            $table->index(['flow_job_id', 'workflow_phase_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number')->unique();
            $table->foreignId('flow_job_id')->nullable()->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category')->nullable();
            $table->string('name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_final')->default(false);
            $table->timestamps();
            $table->index(['flow_job_id', 'category']);
        });

        Schema::create('flow_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flow_job_id')->nullable()->constrained('flow_jobs')->cascadeOnDelete();
            $table->string('type')->default('info');
            $table->string('title');
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger');
            $table->string('recipients');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->text('description');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('flow_notifications');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('flow_jobs');
        Schema::dropIfExists('master_values');
        Schema::dropIfExists('workflow_phases');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('task_pack_tasks');
        Schema::dropIfExists('task_packs');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn(['is_super_admin', 'is_active', 'locale']);
        });
        Schema::dropIfExists('departments');
        Schema::dropIfExists('roles');
    }
};
