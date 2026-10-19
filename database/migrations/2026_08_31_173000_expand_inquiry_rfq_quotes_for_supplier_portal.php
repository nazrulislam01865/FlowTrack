<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inquiry_rfq_quotes')) {
            Schema::table('inquiry_rfq_quotes', function (Blueprint $table): void {
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'supplier_contact_name')) $table->string('supplier_contact_name')->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'supplier_contact_email')) $table->string('supplier_contact_email')->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'supplier_contact_phone')) $table->string('supplier_contact_phone', 80)->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'tooling_cost')) $table->decimal('tooling_cost', 14, 2)->default(0);
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'sample_cost')) $table->decimal('sample_cost', 14, 2)->default(0);
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'discount')) $table->decimal('discount', 14, 2)->default(0);
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'tax_status')) $table->string('tax_status', 24)->default('excluded');
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'sample_lead_time_days')) $table->unsignedInteger('sample_lead_time_days')->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'incoterm')) $table->string('incoterm', 24)->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'shipping_port')) $table->string('shipping_port', 160)->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'estimated_delivery_date')) $table->date('estimated_delivery_date')->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'specification_compliance')) $table->string('specification_compliance', 24)->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'supporting_information')) $table->json('supporting_information')->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'document_notes')) $table->text('document_notes')->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'submitted_by_name')) $table->string('submitted_by_name')->nullable();
                if (! Schema::hasColumn('inquiry_rfq_quotes', 'submitted_by_email')) $table->string('submitted_by_email')->nullable();
            });
        }

        if (Schema::hasTable('inquiry_rfq_quote_items') && ! Schema::hasColumn('inquiry_rfq_quote_items', 'moq')) {
            Schema::table('inquiry_rfq_quote_items', function (Blueprint $table): void {
                $table->decimal('moq', 14, 2)->nullable();
            });
        }

        if (! Schema::hasTable('inquiry_rfq_quote_documents')) {
            Schema::create('inquiry_rfq_quote_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quote_id')->constrained('inquiry_rfq_quotes')->cascadeOnDelete();
                $table->string('document_type', 40)->default('other');
                $table->string('name');
                $table->string('path');
                $table->string('mime_type', 160)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['quote_id', 'document_type'], 'inq_rfq_quote_docs_type_idx');
                $table->index(['quote_id', 'sort_order'], 'inq_rfq_quote_docs_sort_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_rfq_quote_documents');

        if (Schema::hasTable('inquiry_rfq_quote_items') && Schema::hasColumn('inquiry_rfq_quote_items', 'moq')) {
            Schema::table('inquiry_rfq_quote_items', fn (Blueprint $table) => $table->dropColumn('moq'));
        }

        if (Schema::hasTable('inquiry_rfq_quotes')) {
            $columns = [
                'supplier_contact_name', 'supplier_contact_email', 'supplier_contact_phone',
                'tooling_cost', 'sample_cost', 'discount', 'tax_status', 'sample_lead_time_days',
                'incoterm', 'shipping_port', 'estimated_delivery_date', 'specification_compliance',
                'supporting_information', 'document_notes', 'submitted_by_name', 'submitted_by_email',
            ];
            $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('inquiry_rfq_quotes', $column)));
            if ($existing !== []) {
                Schema::table('inquiry_rfq_quotes', fn (Blueprint $table) => $table->dropColumn($existing));
            }
        }
    }
};
