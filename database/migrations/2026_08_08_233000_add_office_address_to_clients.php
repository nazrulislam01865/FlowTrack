<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'office_address')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->string('office_address', 500)->nullable()->after('country');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('clients', 'office_address')) {
            Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('office_address'));
        }
    }
};
