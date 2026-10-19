<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('workflow_templates')) {
            Schema::table('workflow_templates', function (Blueprint $table): void {
                if (!Schema::hasColumn('workflow_templates', 'applies_to')) {
                    $table->string('applies_to', 20)->default('orders')->after('description');
                }
                if (!Schema::hasColumn('workflow_templates', 'client_availability')) {
                    $table->string('client_availability', 20)->default('all')->after('applies_to');
                }
            });

            // Preserve the dedicated Inquiry Workflow behavior that existed before
            // workflow scope was explicit. Other existing workflows stay available
            // to Orders, which matches their previous use in Create Order.
            if (Schema::hasColumn('workflow_templates', 'applies_to')) {
                DB::table('workflow_templates')
                    ->where(function ($query): void {
                        $query->whereRaw('LOWER(name) LIKE ?', ['%inquiry%'])
                            ->orWhereRaw('LOWER(code) LIKE ?', ['%inquiry%']);
                    })
                    ->update(['applies_to' => 'inquiries']);
            }
        }

        if (!Schema::hasTable('workflow_template_client')) {
            Schema::create('workflow_template_client', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_template_id')->constrained('workflow_templates')->cascadeOnDelete();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['workflow_template_id', 'client_id'], 'workflow_template_client_unique');
                $table->index(['client_id', 'workflow_template_id'], 'workflow_template_client_client_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_template_client');

        if (Schema::hasTable('workflow_templates')) {
            Schema::table('workflow_templates', function (Blueprint $table): void {
                $columns = [];
                if (Schema::hasColumn('workflow_templates', 'client_availability')) $columns[] = 'client_availability';
                if (Schema::hasColumn('workflow_templates', 'applies_to')) $columns[] = 'applies_to';
                if ($columns !== []) $table->dropColumn($columns);
            });
        }
    }
};
