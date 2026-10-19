<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_redos', function (Blueprint $table): void {
            $table->dropForeign(['redo_order_id']);
            $table->dropUnique(['redo_order_id']);
        });

        Schema::table('order_redos', function (Blueprint $table): void {
            // A discount-instead-of-redo record has no replacement FlowJob.
            $table->unsignedBigInteger('redo_order_id')->nullable()->change();
            $table->unique('redo_order_id');
            $table->foreign('redo_order_id')
                ->references('id')
                ->on('flow_jobs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // Rows with no redo Order cannot satisfy the previous NOT NULL schema.
        DB::table('order_redos')->whereNull('redo_order_id')->delete();

        Schema::table('order_redos', function (Blueprint $table): void {
            $table->dropForeign(['redo_order_id']);
            $table->dropUnique(['redo_order_id']);
        });

        Schema::table('order_redos', function (Blueprint $table): void {
            $table->unsignedBigInteger('redo_order_id')->nullable(false)->change();
            $table->unique('redo_order_id');
            $table->foreign('redo_order_id')
                ->references('id')
                ->on('flow_jobs')
                ->cascadeOnDelete();
        });
    }
};
