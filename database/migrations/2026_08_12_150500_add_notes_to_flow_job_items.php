<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flow_job_items') && !Schema::hasColumn('flow_job_items', 'notes')) {
            Schema::table('flow_job_items', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('flow_job_items') && Schema::hasColumn('flow_job_items', 'notes')) {
            Schema::table('flow_job_items', function (Blueprint $table): void {
                $table->dropColumn('notes');
            });
        }
    }
};
