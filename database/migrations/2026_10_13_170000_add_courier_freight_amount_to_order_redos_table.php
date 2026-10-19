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
            // Keep freight_amount as the combined amount for legacy reporting.
            $table->decimal('courier_freight_amount', 14, 2)->default(0)->after('freight_amount');
        });

        // One-time reconciliation of courier-only redos saved before this change.
        // No additional reads or queries are required during normal use.
        DB::table('order_redos')
            ->where('freight_bearer', 'courier')
            ->where('freight_amount', '>', 0)
            ->update(['courier_freight_amount' => DB::raw('freight_amount')]);
    }

    public function down(): void
    {
        Schema::table('order_redos', function (Blueprint $table): void {
            $table->dropColumn('courier_freight_amount');
        });
    }
};
