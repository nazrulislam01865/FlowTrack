<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('flow_jobs')) return;
        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('flow_jobs', 'cancellation_reason')) $table->text('cancellation_reason')->nullable();
            if (! Schema::hasColumn('flow_jobs', 'cancelled_at')) $table->timestamp('cancelled_at')->nullable();
            if (! Schema::hasColumn('flow_jobs', 'cancelled_by')) $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('flow_jobs')) return;
        Schema::table('flow_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('flow_jobs', 'cancelled_by')) $table->dropConstrainedForeignId('cancelled_by');
            if (Schema::hasColumn('flow_jobs', 'cancelled_at')) $table->dropColumn('cancelled_at');
            if (Schema::hasColumn('flow_jobs', 'cancellation_reason')) $table->dropColumn('cancellation_reason');
        });
    }
};
