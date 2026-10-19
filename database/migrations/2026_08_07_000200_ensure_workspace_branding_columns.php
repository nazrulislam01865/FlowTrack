<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('workspaces')) return;

        Schema::table('workspaces', function (Blueprint $table) {
            if (!Schema::hasColumn('workspaces', 'logo_path')) {
                $table->string('logo_path')->nullable();
            }

            if (!Schema::hasColumn('workspaces', 'favicon_path')) {
                $table->string('favicon_path')->nullable();
            }
        });
    }

    public function down(): void
    {
        // These columns are part of the canonical FlowTrack workspace schema.
        // Keep them on rollback so older installations that already had them
        // are never destructively altered.
    }
};
