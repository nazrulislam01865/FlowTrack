<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->string('hold_from', 32);
            $table->text('reason');
            $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['flow_job_id', 'ended_at'], 'order_holds_job_active_idx');
            $table->index(['hold_from', 'started_at'], 'order_holds_source_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_holds');
    }
};
