<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_catalog_outbox', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_id')->primary();
            $table->unsignedBigInteger('workspace_id');
            $table->uuid('change_token');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
            $table->index('next_attempt_at');
        });
    }
    public function down(): void { Schema::dropIfExists('supplier_catalog_outbox'); }
};
