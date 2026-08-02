<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_tasks') || Schema::hasColumn('flow_tasks', 'start_date')) {
            return;
        }

        Schema::table('flow_tasks', function (Blueprint $table): void {
            $table->date('start_date')->nullable()->after('start_rule')->index();
        });

        DB::table('flow_tasks')
            ->whereNull('start_date')
            ->update(['start_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_tasks') || ! Schema::hasColumn('flow_tasks', 'start_date')) {
            return;
        }

        Schema::table('flow_tasks', function (Blueprint $table): void {
            $table->dropIndex(['start_date']);
            $table->dropColumn('start_date');
        });
    }
};
