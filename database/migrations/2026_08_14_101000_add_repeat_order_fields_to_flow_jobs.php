<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->boolean('is_repeat_order')->default(false);
            $table->string('repeat_order_number')->nullable();
            $table->index(['is_repeat_order', 'repeat_order_number'], 'flow_jobs_repeat_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flow_jobs', function (Blueprint $table): void {
            $table->dropIndex('flow_jobs_repeat_order_idx');
            $table->dropColumn(['is_repeat_order', 'repeat_order_number']);
        });
    }
};
