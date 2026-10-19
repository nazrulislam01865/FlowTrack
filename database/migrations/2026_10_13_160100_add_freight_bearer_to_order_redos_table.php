<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_redos', function (Blueprint $table): void {
            // Existing redo freight belongs to suppliers; preserve that history.
            $table->string('freight_bearer', 12)->default('supplier')->after('deduct_freight');
        });
    }

    public function down(): void
    {
        Schema::table('order_redos', function (Blueprint $table): void {
            $table->dropColumn('freight_bearer');
        });
    }
};
