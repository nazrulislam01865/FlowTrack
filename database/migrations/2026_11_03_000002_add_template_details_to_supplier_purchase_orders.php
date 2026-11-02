<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_purchase_orders', function (Blueprint $table): void {
            $table->json('template_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_purchase_orders', function (Blueprint $table): void {
            $table->dropColumn('template_details');
        });
    }
};
