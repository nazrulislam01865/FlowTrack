<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_contacts')) {
            // Normalize historical values so case-insensitive uniqueness checks
            // can use the regular email index instead of an expression scan.
            DB::table('client_contacts')
                ->whereNotNull('email')
                ->update(['email' => DB::raw('LOWER(TRIM(email))')]);

            Schema::table('client_contacts', function (Blueprint $table) {
                $table->index('email', 'client_contacts_email_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_contacts')) {
            Schema::table('client_contacts', function (Blueprint $table) {
                $table->dropIndex('client_contacts_email_index');
            });
        }
    }
};
