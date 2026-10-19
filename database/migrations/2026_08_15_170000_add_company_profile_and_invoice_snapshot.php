<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workspaces') && !Schema::hasColumn('workspaces', 'company_profile')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->json('company_profile')->nullable()->after('favicon_path');
            });
        }

        if (Schema::hasTable('invoices') && !Schema::hasColumn('invoices', 'company_snapshot')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->json('company_snapshot')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'company_snapshot')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('company_snapshot');
            });
        }

        if (Schema::hasTable('workspaces') && Schema::hasColumn('workspaces', 'company_profile')) {
            Schema::table('workspaces', function (Blueprint $table) {
                $table->dropColumn('company_profile');
            });
        }
    }
};
