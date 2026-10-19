<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_jobs') || Schema::hasColumn('flow_jobs', 'shipment_method_ids')) {
            return;
        }

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->json('shipment_method_ids')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_jobs') || ! Schema::hasColumn('flow_jobs', 'shipment_method_ids')) {
            return;
        }

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropColumn('shipment_method_ids');
        });
    }
};
