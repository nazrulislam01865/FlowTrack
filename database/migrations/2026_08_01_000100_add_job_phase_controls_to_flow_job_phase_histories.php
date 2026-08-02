<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_job_phase_histories', function (Blueprint $table): void {
            $table->foreignId('phase_owner_id')->nullable()->after('changed_by')->constrained('users')->nullOnDelete();
            $table->date('target_date')->nullable()->after('phase_owner_id');
            $table->string('health_override', 40)->nullable()->after('target_date');
            $table->string('automation_mode', 20)->nullable()->after('health_override');
        });
    }

    public function down(): void
    {
        Schema::table('flow_job_phase_histories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('phase_owner_id');
            $table->dropColumn(['target_date', 'health_override', 'automation_mode']);
        });
    }
};
