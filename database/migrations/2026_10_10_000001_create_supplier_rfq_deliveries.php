<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_rfq_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_id')->unique()->constrained('inquiry_rfq_invitations')->cascadeOnDelete();
            $table->foreignId('supplier_id')->index()->constrained('master_records');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error', 160)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_rfq_deliveries');
    }
};
