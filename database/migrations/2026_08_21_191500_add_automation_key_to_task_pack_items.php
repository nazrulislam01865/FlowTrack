<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_pack_items')) return;

        if (! Schema::hasColumn('task_pack_items', 'automation_key')) {
            Schema::table('task_pack_items', function (Blueprint $table): void {
                $table->string('automation_key', 80)->nullable()->after('title');
                $table->index(['task_pack_id', 'automation_key'], 'task_pack_items_automation_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('task_pack_items') || ! Schema::hasColumn('task_pack_items', 'automation_key')) return;
        Schema::table('task_pack_items', function (Blueprint $table): void {
            $table->dropIndex('task_pack_items_automation_idx');
            $table->dropColumn('automation_key');
        });
    }
};
