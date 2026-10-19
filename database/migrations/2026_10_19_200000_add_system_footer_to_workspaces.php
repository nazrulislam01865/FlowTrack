<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->json('system_footer')->nullable();
        });

        // Remove the old prototype identity only when it is still the exact
        // workspace name. Never overwrite an administrator's custom brand.
        DB::table('workspaces')
            ->whereIn('name', ['STEP PROMO', 'Step Promo', 'StepPromo', 'step promo', 'steppromo'])
            ->update(['name' => 'FlowTrack', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('system_footer');
        });
        // Do not recreate an obsolete workspace brand when rolling back.
    }
};
