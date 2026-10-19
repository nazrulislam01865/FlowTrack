<?php

namespace Tests\Feature;

use App\Services\SupplierBridge\QueueChangedOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SupplierOrderQueueRaceTest extends TestCase
{
    public function test_inflight_delivery_is_not_unlocked_by_a_second_task_change(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('queue.default', 'sync');
        Schema::create('supplier_order_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('flow_job_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
        });
        try {
            DB::table('supplier_order_deliveries')->insert([
                ['flow_job_id' => 14, 'supplier_id' => 7, 'status' => 'sending',
                 'locked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['flow_job_id' => 14, 'supplier_id' => 8, 'status' => 'delivered',
                 'created_at' => now(), 'updated_at' => now()],
            ]);
            app(QueueChangedOrder::class)->mark(14);
            $sending = DB::table('supplier_order_deliveries')->where('supplier_id', 7)->first();
            $this->assertSame('sending', $sending->status);
            $this->assertNotNull($sending->locked_at);
            $this->assertNotNull($sending->next_attempt_at);
            $ready = DB::table('supplier_order_deliveries')->where('supplier_id', 8)->first();
            $this->assertSame('pending', $ready->status);
            $this->assertNull($ready->next_attempt_at);
        } finally {
            Schema::dropIfExists('supplier_order_deliveries');
        }
    }
}
