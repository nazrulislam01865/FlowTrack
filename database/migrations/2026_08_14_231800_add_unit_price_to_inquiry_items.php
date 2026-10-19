<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inquiry_items') && ! Schema::hasColumn('inquiry_items', 'unit_price')) {
            Schema::table('inquiry_items', function (Blueprint $table): void {
                $table->decimal('unit_price', 14, 2)->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inquiry_items') && Schema::hasColumn('inquiry_items', 'unit_price')) {
            Schema::table('inquiry_items', function (Blueprint $table): void {
                $table->dropColumn('unit_price');
            });
        }
    }
};
