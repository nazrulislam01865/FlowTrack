<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_client_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('client_id');
            $table->longText('price_table_raw');
            $table->json('price_breakpoints');
            $table->timestamps();
            $table->unique(['product_id', 'client_id']);
            $table->index(['workspace_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_client_prices');
    }
};
