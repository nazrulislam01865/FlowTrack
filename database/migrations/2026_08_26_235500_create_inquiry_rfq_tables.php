<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inquiry_rfq_invitations')) {
            Schema::create('inquiry_rfq_invitations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->index();
                $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained('master_records')->cascadeOnDelete();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->text('token_cipher');
                $table->dateTime('invited_at')->nullable();
                $table->dateTime('due_at')->nullable()->index();
                $table->string('email_status', 32)->default('Pending');
                $table->uuid('email_tracking_id')->nullable();
                $table->dateTime('reminder_sent_at')->nullable();
                $table->string('interest_status', 24)->default('pending');
                $table->dateTime('interest_at')->nullable();
                $table->string('quote_status', 24)->default('pending');
                $table->dateTime('quote_submitted_at')->nullable();
                $table->dateTime('awarded_at')->nullable();
                $table->dateTime('rejected_at')->nullable();
                $table->dateTime('rejection_notified_at')->nullable();
                $table->timestamps();

                $table->unique(['inquiry_id', 'supplier_id'], 'inq_rfq_invitation_supplier_unique');
                $table->index(['inquiry_id', 'quote_status'], 'inq_rfq_quote_status_idx');
            });
        }

        if (! Schema::hasTable('inquiry_rfq_quotes')) {
            Schema::create('inquiry_rfq_quotes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('invitation_id')->unique()->constrained('inquiry_rfq_invitations')->cascadeOnDelete();
                $table->string('currency', 8)->default('USD');
                $table->decimal('freight', 14, 2)->default(0);
                $table->unsignedInteger('lead_time_days')->nullable();
                $table->unsignedInteger('validity_days')->nullable();
                $table->text('notes')->nullable();
                $table->decimal('submitted_total', 14, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inquiry_rfq_quote_items')) {
            Schema::create('inquiry_rfq_quote_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quote_id')->constrained('inquiry_rfq_quotes')->cascadeOnDelete();
                $table->foreignId('inquiry_item_id')->nullable()->constrained('inquiry_items')->nullOnDelete();
                $table->string('product_name');
                $table->decimal('quantity', 14, 2)->default(1);
                $table->decimal('unit_price', 14, 4)->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['quote_id', 'sort_order'], 'inq_rfq_quote_items_sort_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_rfq_quote_items');
        Schema::dropIfExists('inquiry_rfq_quotes');
        Schema::dropIfExists('inquiry_rfq_invitations');
    }
};
