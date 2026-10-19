<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_shipments') || Schema::hasColumn('order_shipments', 'quantity')) {
            return;
        }

        Schema::table('order_shipments', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->nullable()->after('package_reference');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_shipments') || ! Schema::hasColumn('order_shipments', 'quantity')) {
            return;
        }

        Schema::table('order_shipments', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });
    }
};
