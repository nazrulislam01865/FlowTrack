<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_product_prices', function (Blueprint $table): void {
            // Keep approved unit prices and their proposal version unchanged.
            $table->json('additional_charges')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_product_prices', function (Blueprint $table): void {
            $table->dropColumn('additional_charges');
        });
    }
};
