<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_job_items')) return;

        $needsUnitPrice = ! Schema::hasColumn('flow_job_items', 'unit_price');
        $needsUpdatedBy = ! Schema::hasColumn('flow_job_items', 'updated_by');

        if ($needsUnitPrice || $needsUpdatedBy) {
            Schema::table('flow_job_items', function (Blueprint $table) use ($needsUnitPrice, $needsUpdatedBy): void {
                if ($needsUnitPrice) {
                    $table->decimal('unit_price', 14, 2)->default(0)->after('quantity');
                }
                if ($needsUpdatedBy) {
                    $table->foreignId('updated_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_job_items')) return;

        $hasUpdatedBy = Schema::hasColumn('flow_job_items', 'updated_by');
        $hasUnitPrice = Schema::hasColumn('flow_job_items', 'unit_price');

        if ($hasUpdatedBy || $hasUnitPrice) {
            Schema::table('flow_job_items', function (Blueprint $table) use ($hasUpdatedBy, $hasUnitPrice): void {
                if ($hasUpdatedBy) {
                    $table->dropConstrainedForeignId('updated_by');
                }
                if ($hasUnitPrice) {
                    $table->dropColumn('unit_price');
                }
            });
        }
    }
};
