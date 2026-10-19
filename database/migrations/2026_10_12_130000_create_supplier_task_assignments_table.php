<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_task_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->unique()->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->foreignId('assigned_by')->constrained('users');
            $table->timestamps();
            $table->index(['flow_job_id', 'supplier_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('supplier_task_assignments'); }
};
