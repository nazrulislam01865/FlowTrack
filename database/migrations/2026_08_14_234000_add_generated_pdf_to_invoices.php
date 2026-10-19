<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('pdf_path')->nullable()->after('supporting_document_name');
            $table->string('pdf_name')->nullable()->after('pdf_path');
            $table->timestamp('pdf_generated_at')->nullable()->after('pdf_name');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['pdf_path', 'pdf_name', 'pdf_generated_at']);
        });
    }
};
