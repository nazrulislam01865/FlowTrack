<?php

namespace Tests\Feature;

use App\Jobs\DeliverSupplierBranding;
use App\Jobs\DeliverChangedSupplierOrder;
use App\Services\SupplierBridge\MarkBrandDirty;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SupplierCriticalSyncRoutingTest extends TestCase
{
    public function test_brand_outbox_can_skip_heavy_catalogue_push_and_preserve_race_token(): void
    {
        config()->set('supplier_bridge.enabled', true);
        config()->set('queue.default', 'database');
        Queue::fake();
        Schema::create('supplier_brand_outbox', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_id')->primary();
            $table->uuid('change_token');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
        try {
            app(MarkBrandDirty::class)->supplierIds([7, 7]);
            $first = DB::table('supplier_brand_outbox')->where('supplier_id', 7)->value('change_token');
            $this->assertNotEmpty($first);
            Queue::assertPushed(DeliverSupplierBranding::class, 1);
            app(MarkBrandDirty::class)->supplierIds([7]);
            $this->assertNotSame($first, DB::table('supplier_brand_outbox')->where('supplier_id', 7)->value('change_token'));
            Queue::assertPushed(DeliverSupplierBranding::class, 2);
            $this->assertSame(1, DB::table('supplier_brand_outbox')->count());
        } finally {
            Schema::dropIfExists('supplier_brand_outbox');
        }
    }

    public function test_task_order_delivery_uses_dedicated_queue(): void
    {
        $source = file_get_contents(app_path('Services/SupplierBridge/QueueChangedOrder.php'));
        $this->assertStringContainsString("->onQueue('supplier-critical')", $source);
        $this->assertStringNotContainsString("->onQueue('supplier-sync')", $source);
        $this->assertTrue(class_exists(DeliverChangedSupplierOrder::class));
    }
}
