<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_product_currency_tables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('currency', 3);
            $table->json('price_breakpoints');
            $table->json('additional_charges')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'supplier_id', 'currency'], 'supplier_currency_product_unique');
            $table->index(['workspace_id', 'product_id', 'supplier_id'], 'supplier_currency_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_currency_tables');
    }
};
