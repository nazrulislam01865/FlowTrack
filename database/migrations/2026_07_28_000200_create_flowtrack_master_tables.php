<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->string('type', 40);
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'type', 'code']);
            $table->index(['workspace_id', 'type', 'status']);
        });

        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->string('job_title')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('country', 100)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('preferred_language', 20)->default('en');
            $table->decimal('outstanding_balance', 15, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('master_records');
    }
};
