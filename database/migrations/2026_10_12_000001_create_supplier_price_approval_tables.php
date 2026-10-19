<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_product_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id');
            $table->json('price_breakpoints');
            $table->timestamps();
            $table->unique(['supplier_id', 'product_id']);
            $table->index(['workspace_id', 'product_id']);
        });
        Schema::create('supplier_price_proposals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('base_version', 64);
            $table->json('proposed_prices');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['supplier_id', 'status']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('supplier_price_proposals');
        Schema::dropIfExists('supplier_product_prices');
    }
};
