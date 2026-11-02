<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_purchase_orders', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
            $table->index(['task_id', 'archived_at'], 'supplier_po_task_archive_idx');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('supplier_po_task_archive_idx');
            $table->dropColumn('archived_at');
        });
    }
};
