<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_task_action_receipts', function (Blueprint $table): void {
            $table->uuid('request_uuid')->primary();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('supplier_id');
            $table->timestamp('created_at');
            $table->index(['supplier_id', 'task_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('supplier_task_action_receipts'); }
};
