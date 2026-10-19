<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_holds') || Schema::hasColumn('order_holds', 'source_name')) {
            return;
        }

        Schema::table('order_holds', function (Blueprint $table): void {
            $table->string('source_name')->nullable()->after('hold_from');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_holds') || ! Schema::hasColumn('order_holds', 'source_name')) {
            return;
        }

        Schema::table('order_holds', function (Blueprint $table): void {
            $table->dropColumn('source_name');
        });
    }
};
