<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', function (Blueprint $table): void {
                // Orders list: exclude soft-deleted/inactive rows, then stream the
                // newest visible records without sorting the complete table.
                $table->index(
                    ['deleted_at', 'status', 'created_at', 'id'],
                    'ft_orders_list_created_idx',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('flow_jobs')) {
            Schema::table('flow_jobs', fn (Blueprint $table) =>
                $table->dropIndex('ft_orders_list_created_idx')
            );
        }
    }
};
