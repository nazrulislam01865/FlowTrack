<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('flow_jobs', 'supplier_delivery_date')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->date('supplier_delivery_date')->nullable()->after('estimated_delivery_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('flow_jobs', 'supplier_delivery_date')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                $table->dropColumn('supplier_delivery_date');
            });
        }
    }
};
