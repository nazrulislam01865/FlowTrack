<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('flow_job_inquiries')) {
            Schema::create('flow_job_inquiries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
                $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
                $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // One Order may have many Inquiries, but an Inquiry remains linked
                // to at most one Order. This preserves the existing Inquiry -> Order
                // ownership rule while removing the one-Inquiry-per-Order limit.
                $table->unique('inquiry_id', 'flow_job_inquiries_inquiry_unique');
                $table->unique(['flow_job_id', 'inquiry_id'], 'flow_job_inquiries_pair_unique');
                $table->index(['flow_job_id', 'created_at'], 'flow_job_inquiries_order_created_idx');
            });
        }

        // Backfill the legacy primary/source relationship so existing Orders keep
        // exactly the same Inquiry link after the application starts reading the
        // new many-link relation.
        DB::table('flow_jobs')
            ->whereNull('deleted_at')
            ->whereNotNull('source_inquiry_id')
            ->select(['id', 'source_inquiry_id', 'created_by', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(250, function ($jobs): void {
                foreach ($jobs as $job) {
                    DB::table('flow_job_inquiries')->insertOrIgnore([
                        'flow_job_id' => (int) $job->id,
                        'inquiry_id' => (int) $job->source_inquiry_id,
                        'linked_by' => $job->created_by ? (int) $job->created_by : null,
                        'created_at' => $job->created_at ?? now(),
                        'updated_at' => $job->updated_at ?? now(),
                    ]);
                }
            });

        // Repair older drift where the Inquiry reverse pointer exists but the
        // FlowJob source pointer does not. The unique Inquiry index prevents an
        // inconsistent record from being attached to two Orders.
        DB::table('inquiries')
            ->whereNull('deleted_at')
            ->whereNotNull('converted_job_id')
            ->select(['id', 'converted_job_id', 'created_by', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(250, function ($inquiries): void {
                foreach ($inquiries as $inquiry) {
                    $orderExists = DB::table('flow_jobs')
                        ->where('id', (int) $inquiry->converted_job_id)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$orderExists) {
                        continue;
                    }

                    DB::table('flow_job_inquiries')->insertOrIgnore([
                        'flow_job_id' => (int) $inquiry->converted_job_id,
                        'inquiry_id' => (int) $inquiry->id,
                        'linked_by' => $inquiry->created_by ? (int) $inquiry->created_by : null,
                        'created_at' => $inquiry->created_at ?? now(),
                        'updated_at' => $inquiry->updated_at ?? now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_job_inquiries');
    }
};
