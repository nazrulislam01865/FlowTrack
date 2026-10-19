<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            if (! Schema::hasColumn('inquiries', 'needs_attention')) {
                $table->boolean('needs_attention')->default(false)->after('status')->index();
            }
            if (! Schema::hasColumn('inquiries', 'attention_reason')) {
                $table->text('attention_reason')->nullable()->after('needs_attention');
            }
            if (! Schema::hasColumn('inquiries', 'attention_by')) {
                $table->foreignId('attention_by')->nullable()->after('attention_reason')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('inquiries', 'attention_at')) {
                $table->timestamp('attention_at')->nullable()->after('attention_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            if (Schema::hasColumn('inquiries', 'attention_by')) {
                $table->dropConstrainedForeignId('attention_by');
            }
            foreach (['attention_at', 'attention_reason', 'needs_attention'] as $column) {
                if (Schema::hasColumn('inquiries', $column)) $table->dropColumn($column);
            }
        });
    }
};
