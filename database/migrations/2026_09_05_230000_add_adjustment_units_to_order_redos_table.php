<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_redos', function (Blueprint $table): void {
            $table->string('customer_adjustment_type', 12)->default('percent')->after('customer_resolution');
            $table->decimal('customer_adjustment_value', 10, 2)->default(0)->after('customer_adjustment_type');
            $table->string('supplier_adjustment_type', 12)->default('percent')->after('supplier_redo_charge_percent');
            $table->decimal('supplier_adjustment_value', 10, 2)->default(0)->after('supplier_adjustment_type');
            $table->decimal('order_value_before_adjustment', 14, 2)->default(0)->after('affected_order_value');
            $table->decimal('order_value_after_adjustment', 14, 2)->default(0)->after('order_value_before_adjustment');
        });

        // Preserve all existing Redo records. Older rows only stored percentage
        // values, so copy those values into the new unit-aware columns.
        DB::statement('UPDATE order_redos SET customer_adjustment_value = customer_discount_percent, supplier_adjustment_value = supplier_redo_charge_percent');
    }

    public function down(): void
    {
        Schema::table('order_redos', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_adjustment_type',
                'customer_adjustment_value',
                'supplier_adjustment_type',
                'supplier_adjustment_value',
                'order_value_before_adjustment',
                'order_value_after_adjustment',
            ]);
        });
    }
};
