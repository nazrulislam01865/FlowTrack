<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_redos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_order_id')->constrained('flow_jobs')->restrictOnDelete();
            $table->foreignId('redo_order_id')->unique()->constrained('flow_jobs')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('issue_reported_by', 60);
            $table->string('issue_category', 120);
            $table->date('reported_date');
            $table->unsignedInteger('affected_quantity');
            $table->text('issue_description');
            $table->string('scope', 30);
            $table->unsignedInteger('redo_quantity');
            $table->foreignId('supplier_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->text('internal_instructions')->nullable();
            $table->string('customer_resolution', 30);
            $table->decimal('customer_discount_percent', 6, 2)->default(0);
            $table->decimal('supplier_redo_charge_percent', 6, 2)->default(0);
            $table->boolean('deduct_freight')->default(false);
            $table->decimal('freight_amount', 14, 2)->default(0);
            $table->decimal('affected_order_value', 14, 2)->default(0);
            $table->decimal('customer_impact', 14, 2)->default(0);
            $table->decimal('supplier_redo_charge', 14, 2)->default(0);
            $table->decimal('total_supplier_recovery', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['original_order_id', 'sequence'], 'order_redos_original_sequence_unique');
            $table->index(['original_order_id', 'created_at'], 'order_redos_original_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_redos');
    }
};
