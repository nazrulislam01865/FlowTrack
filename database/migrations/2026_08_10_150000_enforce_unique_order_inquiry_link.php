<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Soft-deleted Orders must not reserve an Inquiry link forever.
        DB::table('flow_jobs')->whereNotNull('deleted_at')->whereNotNull('source_inquiry_id')->update(['source_inquiry_id' => null]);

        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->unique('source_inquiry_id', 'flow_jobs_source_inquiry_unique');
        });
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropUnique('flow_jobs_source_inquiry_unique');
        });
    }
};
