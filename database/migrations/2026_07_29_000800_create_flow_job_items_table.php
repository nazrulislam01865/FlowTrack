<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_job_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_job_id')->constrained('flow_jobs')->cascadeOnDelete();
            $table->foreignId('product_category_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('master_records')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['flow_job_id', 'sort_order']);
            $table->index(['product_id', 'product_category_id']);
        });

        $now = now();
        DB::table('flow_jobs')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->select(['id', 'product_id', 'product_category_id', 'quantity'])
            ->chunkById(500, function ($jobs) use ($now): void {
                $rows = $jobs->map(fn ($job) => [
                    'flow_job_id' => $job->id,
                    'product_category_id' => $job->product_category_id,
                    'product_id' => $job->product_id,
                    'quantity' => max(1, (int) $job->quantity),
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('flow_job_items')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_job_items');
    }
};
