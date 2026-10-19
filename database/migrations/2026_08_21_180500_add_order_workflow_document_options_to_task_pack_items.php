<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_pack_items')) return;

        Schema::table('task_pack_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('task_pack_items', 'document_required_before_completion')) {
                $table->boolean('document_required_before_completion')->default(true)->after('document_category_id');
            }
            if (! Schema::hasColumn('task_pack_items', 'allow_multiple_documents')) {
                $table->boolean('allow_multiple_documents')->default(false)->after('document_required_before_completion');
            }
            if (! Schema::hasColumn('task_pack_items', 'document_instructions')) {
                $table->text('document_instructions')->nullable()->after('allow_multiple_documents');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('task_pack_items')) return;

        Schema::table('task_pack_items', function (Blueprint $table): void {
            foreach (['document_instructions', 'allow_multiple_documents', 'document_required_before_completion'] as $column) {
                if (Schema::hasColumn('task_pack_items', $column)) $table->dropColumn($column);
            }
        });
    }
};
