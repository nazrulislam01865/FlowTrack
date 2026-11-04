<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // New purchase orders belong to an Order, not to an Artwork task.
        // Historical PO/task links and document records are deliberately retained.
        Schema::table('supplier_purchase_orders', function (Blueprint $table): void {
            $table->dropForeign(['task_id']);
            $table->foreignId('task_id')->nullable()->change();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
            $table->index(['flow_job_id', 'archived_at', 'id'], 'supplier_po_order_active_idx');
        });

        if (!Schema::hasTable('task_pack_items')) return;

        DB::transaction(function (): void {
            $ids = DB::table('task_pack_items')
                ->where('automation_key', 'ART_CREATE_SUPPLIER_PO')->pluck('id');
            if ($ids->isEmpty()) return;

            // Hide only the generated PO workflow task; retain its row (soft
            // delete) for existing documents, task history and audit references.
            DB::table('tasks')->whereIn('task_pack_task_id', $ids)
                ->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);

            // The old mirror is the FK target for tasks.task_pack_task_id. Its
            // nullOnDelete FK safely clears the association on historic tasks.
            if (Schema::hasTable('task_pack_tasks')) {
                DB::table('task_pack_tasks')->whereIn('id', $ids)->delete();
            }
            DB::table('task_pack_items')->whereIn('id', $ids)->delete();
        });
    }

    public function down(): void
    {
        // Do not recreate a removed Artwork step or unarchive historic tasks.
        Schema::table('supplier_purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('supplier_po_order_active_idx');
        });
    }
};
