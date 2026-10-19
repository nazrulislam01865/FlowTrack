<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('invoice_number', 40)->unique();
            $table->string('type', 40)->default('Final invoice');
            $table->string('currency', 3)->default('USD');
            $table->date('issue_date');
            $table->date('due_date');
            $table->foreignId('billing_contact_id')->nullable()->constrained('client_contacts')->nullOnDelete();
            $table->string('billing_contact_name')->nullable();
            $table->string('billing_contact_email')->nullable();
            $table->string('purchase_order_reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('supporting_document_path')->nullable();
            $table->string('supporting_document_name')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('previously_invoiced', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('status', 30)->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['flow_job_id', 'sequence']);
            $table->index(['flow_job_id', 'status', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['invoice_id', 'sort_order']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('payment_number', 40)->unique();
            $table->date('payment_date');
            $table->string('method', 60)->default('Bank transfer');
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['flow_job_id', 'sequence']);
            $table->index(['flow_job_id', 'payment_date']);
            $table->index(['invoice_id', 'payment_date']);
        });

        Schema::create('flow_job_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_id')->unique()->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('collection_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('last_follow_up_at')->nullable();
            $table->date('next_follow_up_at')->nullable();
            $table->text('latest_note')->nullable();
            $table->timestamps();
            $table->index(['collection_owner_id', 'next_follow_up_at']);
        });

        Schema::create('collection_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flow_job_collection_id')->constrained('flow_job_collections')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('follow_up_date')->nullable();
            $table->date('next_follow_up_at')->nullable();
            $table->text('note');
            $table->string('type', 40)->default('update');
            $table->timestamps();
            $table->index(['flow_job_collection_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_updates');
        Schema::dropIfExists('flow_job_collections');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
