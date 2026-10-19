<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_records') || Schema::hasColumn('master_records', 'created_by')) {
            return;
        }

        Schema::table('master_records', function (Blueprint $table): void {
            $table->foreignId('created_by')
                ->nullable()
                ->after('workspace_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('master_records') || ! Schema::hasColumn('master_records', 'created_by')) {
            return;
        }

        Schema::table('master_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
