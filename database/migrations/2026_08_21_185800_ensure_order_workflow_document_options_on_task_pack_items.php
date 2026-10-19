<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('task_pack_items')) return;

        if (! Schema::hasColumn('task_pack_items', 'document_required_before_completion')) {
            Schema::table('task_pack_items', function (Blueprint $table): void {
                $table->boolean('document_required_before_completion')->default(true)->after('document_category_id');
            });
        }

        if (! Schema::hasColumn('task_pack_items', 'allow_multiple_documents')) {
            Schema::table('task_pack_items', function (Blueprint $table): void {
                $table->boolean('allow_multiple_documents')->default(false)->after('document_required_before_completion');
            });
        }

        if (! Schema::hasColumn('task_pack_items', 'document_instructions')) {
            Schema::table('task_pack_items', function (Blueprint $table): void {
                $table->text('document_instructions')->nullable()->after('allow_multiple_documents');
            });
        }
    }

    public function down(): void
    {
        // This is a repair migration. Do not remove columns in down() because
        // an older migration may be the original owner of the same fields.
    }
};
