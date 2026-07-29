<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('flow_jobs', 'request_description')) {
                $table->text('request_description')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_jobs', 'request_description')) {
                $table->dropColumn('request_description');
            }
        });
    }
};
