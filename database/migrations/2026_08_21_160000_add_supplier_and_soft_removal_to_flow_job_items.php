<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_job_items')) return;

        Schema::table('flow_job_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('flow_job_items', 'catalog_product_id')) {
                $table->foreignId('catalog_product_id')->nullable()->after('flow_job_id')->constrained('master_records')->nullOnDelete();
            }
            if (! Schema::hasColumn('flow_job_items', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('catalog_product_id')->constrained('master_records')->nullOnDelete();
            }
            if (! Schema::hasColumn('flow_job_items', 'is_removed')) {
                $table->boolean('is_removed')->default(false)->after('notes');
                $table->index(['flow_job_id', 'is_removed', 'sort_order'], 'flow_job_items_active_sort_idx');
            }
            if (! Schema::hasColumn('flow_job_items', 'removed_at')) {
                $table->timestamp('removed_at')->nullable()->after('is_removed');
            }
            if (! Schema::hasColumn('flow_job_items', 'removed_by')) {
                $table->foreignId('removed_by')->nullable()->after('removed_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('flow_job_items', 'removal_reason')) {
                $table->text('removal_reason')->nullable()->after('removed_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_job_items')) return;

        Schema::table('flow_job_items', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_job_items', 'supplier_id')) $table->dropConstrainedForeignId('supplier_id');
            if (Schema::hasColumn('flow_job_items', 'catalog_product_id')) $table->dropConstrainedForeignId('catalog_product_id');
            if (Schema::hasColumn('flow_job_items', 'removed_by')) $table->dropConstrainedForeignId('removed_by');
            if (Schema::hasColumn('flow_job_items', 'is_removed')) {
                $table->dropIndex('flow_job_items_active_sort_idx');
                $table->dropColumn('is_removed');
            }
            foreach (['removed_at', 'removal_reason'] as $column) {
                if (Schema::hasColumn('flow_job_items', $column)) $table->dropColumn($column);
            }
        });
    }
};
