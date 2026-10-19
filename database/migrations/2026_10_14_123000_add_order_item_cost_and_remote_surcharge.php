<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('flow_job_items', function (Blueprint $table): void {
            $table->decimal('supplier_unit_price', 14, 2)->nullable()->after('unit_price');
            $table->decimal('remote_area_surcharge', 14, 2)->nullable()->after('supplier_unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('flow_job_items', function (Blueprint $table): void {
            $table->dropColumn(['supplier_unit_price', 'remote_area_surcharge']);
        });
    }
};
