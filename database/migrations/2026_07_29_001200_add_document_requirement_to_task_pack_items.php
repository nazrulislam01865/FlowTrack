<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_pack_items', function (Blueprint $table): void {
            $table->foreignId('document_category_id')
                ->nullable()
                ->after('priority_id')
                ->constrained('master_records')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_pack_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_category_id');
        });
    }
};
