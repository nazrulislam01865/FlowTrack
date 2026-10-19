<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_quotation_inbox_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_id')->constrained('inquiry_rfq_invitations')->cascadeOnDelete();
            $table->unsignedBigInteger('external_document_id');
            $table->string('path');
            $table->string('mime_type',160);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->timestamps();
            $table->unique(['invitation_id','external_document_id'], 'supplier_quote_inbox_file_unique');
        });
        Schema::table('inquiry_rfq_quotes', function (Blueprint $table): void {
            $table->uuid('portal_submission_uuid')->nullable()->unique();
            $table->json('portal_details')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('inquiry_rfq_quotes', fn (Blueprint $table) => $table->dropUnique(['portal_submission_uuid']));
        Schema::table('inquiry_rfq_quotes', fn (Blueprint $table) => $table->dropColumn(['portal_submission_uuid','portal_details']));
        Schema::dropIfExists('supplier_quotation_inbox_files');
    }
};
