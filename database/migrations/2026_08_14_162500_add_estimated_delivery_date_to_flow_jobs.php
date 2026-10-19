<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->date('estimated_delivery_date')->nullable()->after('delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropColumn('estimated_delivery_date');
        });
    }
};
