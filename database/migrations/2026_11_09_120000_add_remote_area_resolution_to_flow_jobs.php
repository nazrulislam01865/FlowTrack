<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->string('remote_area_resolution_status', 20)->nullable();
            $table->string('remote_area_lookup_key', 30)->nullable();
            $table->char('remote_area_input_fingerprint', 64)->nullable();
            $table->unsignedBigInteger('remote_area_rule_id')->nullable();
            $table->string('remote_area_name_snapshot')->nullable();
            $table->string('remote_area_location_snapshot')->nullable();
            $table->decimal('remote_area_charge', 15, 2)->nullable();
            $table->unsignedBigInteger('remote_area_rule_revision')->nullable();
            $table->unsignedBigInteger('remote_area_workspace_id')->nullable();
            $table->dateTime('remote_area_resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropColumn([
                'remote_area_resolution_status', 'remote_area_lookup_key', 'remote_area_input_fingerprint',
                'remote_area_rule_id', 'remote_area_name_snapshot', 'remote_area_location_snapshot',
                'remote_area_charge', 'remote_area_rule_revision', 'remote_area_workspace_id', 'remote_area_resolved_at',
            ]);
        });
    }
};
