<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $tableName = 'supplier_artwork_reminder_settings';

        // MySQL schema changes may survive a failed migration. Safely resume
        // without re-adding columns that were already created.
        if (! Schema::hasColumn($tableName, 'name')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('name', 120)->default('Supplier · Artwork Confirmed');
            });
        }

        if (! Schema::hasColumn($tableName, 'is_active')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('is_active')->default(false);
            });
        }

        // The foreign key on workspace_id must keep a supporting index.
        // The composite index also serves the active-reminder lookup.
        if (! Schema::hasIndex($tableName, 'supplier_artwork_reminder_active_idx')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index(['workspace_id', 'is_active'], 'supplier_artwork_reminder_active_idx');
            });
        }

        // Only remove the single-workspace UNIQUE index after its replacement exists.
        if (Schema::hasIndex($tableName, 'supplier_artwork_reminder_settings_workspace_id_unique')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropUnique('supplier_artwork_reminder_settings_workspace_id_unique');
            });
        }

        // Retain the existing published reminder as active.
        DB::table($tableName)->whereNotNull('published')->update(['is_active' => true]);
    }

    public function down(): void
    {
        $tableName = 'supplier_artwork_reminder_settings';

        $duplicate = DB::table($tableName)
            ->select('workspace_id')
            ->groupBy('workspace_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicate) {
            throw new RuntimeException('Cannot roll back: multiple reminder templates exist in a workspace. Preserve these records before reverting.');
        }

        // Recreate the single-workspace unique index before dropping the
        // composite index, so the foreign key remains supported throughout.
        if (! Schema::hasIndex($tableName, 'supplier_artwork_reminder_settings_workspace_id_unique')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unique('workspace_id');
            });
        }

        if (Schema::hasIndex($tableName, 'supplier_artwork_reminder_active_idx')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex('supplier_artwork_reminder_active_idx');
            });
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn(['name', 'is_active']);
        });
    }
};
