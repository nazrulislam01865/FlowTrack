<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_product_global_policies', function (Blueprint $table): void {
            $table->unsignedBigInteger('workspace_id')->primary();
            $table->boolean('enabled')->default(false);
            $table->json('permissions');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_global_policies');
    }
};
