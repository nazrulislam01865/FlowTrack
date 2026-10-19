<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspace_memberships') || ! Schema::hasColumn('workspace_memberships', 'role_id')) {
            return;
        }

        // Fresh SQLite databases already receive the nullable/null-on-delete
        // definition from the original table-creation migration. Production is
        // MySQL, where older installations need the existing FK upgraded.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
        });

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id')->nullable()->change();
        });

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspace_memberships') || ! Schema::hasColumn('workspace_memberships', 'role_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            // Keep the column nullable on rollback so users whose only role was
            // deleted are never removed from the workspace or assigned a fake role.
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
        });
    }
};
