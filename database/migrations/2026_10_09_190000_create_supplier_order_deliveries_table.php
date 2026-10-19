<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_order_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('master_records');
            $table->uuid('event_id')->unique();
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('last_error', 160)->nullable();
            $table->timestamps();
            $table->unique(['flow_job_id', 'supplier_id'], 'supplier_order_once_idx');
            $table->index(['status', 'next_attempt_at'], 'supplier_delivery_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_order_deliveries');
    }
};
