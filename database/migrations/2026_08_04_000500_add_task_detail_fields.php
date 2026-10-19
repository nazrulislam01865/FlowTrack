<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tasks')) return;

        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (!Schema::hasColumn('tasks', 'start_date')) {
                $table->date('start_date')->nullable()->after('progress');
            }
        });

        if (Schema::hasColumn('tasks', 'start_date')) {
            DB::table('tasks')->whereNull('start_date')->update([
                'start_date' => DB::raw('DATE(created_at)'),
            ]);
        }
    }

    public function down(): void
    {
        // Compatibility migration is intentionally non-destructive.
    }
};
