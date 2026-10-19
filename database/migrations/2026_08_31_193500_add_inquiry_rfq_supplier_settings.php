<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inquiry_rfq_settings')) {
            Schema::create('inquiry_rfq_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('inquiry_id')->unique()->constrained()->cascadeOnDelete();
                $table->text('special_note')->nullable();
                $table->text('supplier_details')->nullable();
                $table->dateTime('default_due_at')->nullable();
                $table->unsignedSmallInteger('link_validity_hours')->default(720);
                $table->boolean('auto_reply_enabled')->default(true);
                $table->boolean('reminder_enabled')->default(true);
                $table->unsignedSmallInteger('reminder_hours_before_due')->default(24);
                $table->boolean('allow_revision')->default(true);
                $table->boolean('award_email_enabled')->default(true);
                $table->boolean('not_selected_email_enabled')->default(true);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inquiry_rfq_invitations')) {
            return;
        }

        $missing = collect([
            'supplier_details',
            'link_expires_at',
            'auto_reply_enabled',
            'reminder_enabled',
            'reminder_hours_before_due',
            'allow_revision',
        ])->reject(fn (string $column): bool => Schema::hasColumn('inquiry_rfq_invitations', $column));

        if ($missing->isEmpty()) {
            return;
        }

        Schema::table('inquiry_rfq_invitations', function (Blueprint $table) use ($missing): void {
            if ($missing->contains('supplier_details')) {
                $table->text('supplier_details')->nullable();
            }
            if ($missing->contains('link_expires_at')) {
                $table->dateTime('link_expires_at')->nullable()->index();
            }
            if ($missing->contains('auto_reply_enabled')) {
                $table->boolean('auto_reply_enabled')->default(true);
            }
            if ($missing->contains('reminder_enabled')) {
                $table->boolean('reminder_enabled')->default(true);
            }
            if ($missing->contains('reminder_hours_before_due')) {
                $table->unsignedSmallInteger('reminder_hours_before_due')->default(24);
            }
            if ($missing->contains('allow_revision')) {
                $table->boolean('allow_revision')->default(true);
                $table->boolean('award_email_enabled')->default(true);
                $table->boolean('not_selected_email_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('inquiry_rfq_invitations')) {
            $columns = collect([
                'supplier_details',
                'link_expires_at',
                'auto_reply_enabled',
                'reminder_enabled',
                'reminder_hours_before_due',
                'allow_revision',
            ])->filter(fn (string $column): bool => Schema::hasColumn('inquiry_rfq_invitations', $column))->values()->all();

            if ($columns !== []) {
                Schema::table('inquiry_rfq_invitations', function (Blueprint $table) use ($columns): void {
                    $table->dropColumn($columns);
                });
            }
        }

        Schema::dropIfExists('inquiry_rfq_settings');
    }
};
