<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['workspace_id', 'code']);
        });

        Schema::create('task_pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_pack_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('default_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('default_department_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('priority_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->unsignedInteger('due_offset_days')->default(1);
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['workspace_id', 'code']);
        });

        Schema::create('workflow_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_category_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->string('name');
            $table->string('short_name', 50);
            $table->unsignedInteger('sequence');
            $table->boolean('allow_job_start')->default(false);
            $table->boolean('is_skippable')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('entry_condition')->nullable();
            $table->string('exit_condition')->nullable();
            $table->timestamps();
            $table->unique(['workflow_template_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_phases');
        Schema::dropIfExists('workflow_templates');
        Schema::dropIfExists('task_pack_items');
        Schema::dropIfExists('task_packs');
    }
};
