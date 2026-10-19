<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_shipments') || Schema::hasColumn('order_shipments', 'courier_id')) {
            return;
        }

        Schema::table('order_shipments', function (Blueprint $table): void {
            $table->foreignId('courier_id')
                ->nullable()
                ->after('shipment_urgency_id')
                ->constrained('master_records')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally left unchanged. This is a repair migration that makes
        // the shipment schema converge safely on installations where the
        // earlier courier migration was skipped or recorded inconsistently.
    }
};
