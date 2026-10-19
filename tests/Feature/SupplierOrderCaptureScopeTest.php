<?php

namespace Tests\Feature;

use App\Models\FlowJob;
use App\Services\SupplierBridge\CaptureCreatedOrder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupplierOrderCaptureScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('supplier_bridge.enabled', true);
        config()->set('queue.default', 'sync');

        Schema::create('master_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('type');
            $table->string('name')->default('');
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->softDeletes();
        });
        Schema::create('product_supplier_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id');
        });
        Schema::create('flow_job_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('flow_job_id');
            $table->unsignedBigInteger('catalog_product_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->boolean('is_removed')->default(false);
        });
        Schema::create('supplier_order_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('flow_job_id');
            $table->unsignedBigInteger('supplier_id');
            $table->uuid('event_id');
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('supplier_order_deliveries');
        Schema::dropIfExists('flow_job_items');
        Schema::dropIfExists('product_supplier_links');
        Schema::dropIfExists('master_records');
        parent::tearDown();
    }

    public function test_order_is_delivered_only_to_selected_suppliers_linked_to_its_products(): void
    {
        $this->supplier(10);
        $this->supplier(20);
        $this->supplier(30);
        $this->product(110, ['supplier_id' => 10, 'supplier_ids' => [10, 20]]);
        $this->product(120, []);
        $this->product(130, []);
        DB::table('product_supplier_links')->insert([
            'workspace_id' => 1, 'product_id' => 120, 'supplier_id' => 20,
        ]);

        $this->line(110, 10, 'Alpha product', 4);
        $this->line(110, 20, 'Alpha product', 2);
        $this->line(120, 20, 'Beta product', 8);
        $this->line(120, 10, 'Invalid product/supplier assignment', 3);
        $this->line(130, 30, 'Product with no assigned supplier', 10);
        $this->line(110, null, 'Unassigned product', 7);
        $this->line(null, 30, 'No catalog product', 9);
        $this->line(120, 20, 'Removed product', 1, true);

        app(CaptureCreatedOrder::class)->capture($this->order());

        $deliveries = DB::table('supplier_order_deliveries')->orderBy('supplier_id')->get();
        $this->assertCount(2, $deliveries);
        $this->assertSame([10, 20], $deliveries->pluck('supplier_id')->map(fn ($id) => (int) $id)->all());
        $first = json_decode($deliveries[0]->payload, true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode($deliveries[1]->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Catalog 110'], array_column($first['order']['items'], 'product_name'));
        $this->assertSame(['Catalog 110', 'Catalog 120'], array_column($second['order']['items'], 'product_name'));
        $this->assertSame(10, $first['supplier_id']);
        $this->assertSame(20, $second['supplier_id']);
        $this->assertArrayNotHasKey('client_id', $first['order']);
        $this->assertArrayNotHasKey('shipping_address', $first['order']);
        $this->assertArrayNotHasKey('unit_price', $first['order']['items'][0]);
    }

    public function test_order_with_no_assigned_product_supplier_creates_no_delivery(): void
    {
        $this->supplier(10);
        $this->product(110, []);
        $this->line(110, null, 'Not assigned', 3);
        $this->line(110, 10, 'Unlinked supplier', 5);

        app(CaptureCreatedOrder::class)->capture($this->order());

        $this->assertSame(0, DB::table('supplier_order_deliveries')->count());
    }

    public function test_historical_batch_creates_supplier_scoped_deliveries_once_only(): void
    {
        $this->supplier(10);
        $this->supplier(20);
        $this->product(110, ['supplier_id' => 10]);
        $this->product(120, ['supplier_id' => 20]);
        $this->line(110, 10, 'Untrusted order title', 5);
        $this->line(120, 20, 'Other suppliers item', 3);
        $this->line(110, null, 'Not selected', 1);
        $order = $this->order();
        $first = app(CaptureCreatedOrder::class)->captureMany([$order]);
        $second = app(CaptureCreatedOrder::class)->captureMany([$order]);
        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['existing']);
        $this->assertSame(2, DB::table('supplier_order_deliveries')->count());
        $deliveries = DB::table('supplier_order_deliveries')->orderBy('supplier_id')->get();
        $a = json_decode($deliveries[0]->payload, true, 512, JSON_THROW_ON_ERROR);
        $b = json_decode($deliveries[1]->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Catalog 110'], array_column($a['order']['items'], 'product_name'));
        $this->assertSame(['Catalog 120'], array_column($b['order']['items'], 'product_name'));
        $this->assertSame('PRD-000110', $a['order']['items'][0]['product_code']);
        $this->assertArrayNotHasKey('client_id', $a['order']);
        $this->assertArrayNotHasKey('shipping_address', $b['order']);
    }

    private function order(): FlowJob
    {
        $order = new FlowJob();
        $order->setRawAttributes([
            'id' => 501, 'job_number' => 'ORDER-501', 'status' => 'New',
            'supplier_id' => 30, // Never used as a fallback.
            'created_at' => now()->toDateTimeString(),
        ]);
        return $order;
    }

    private function supplier(int $id): void
    {
        DB::table('master_records')->insert([
            'id' => $id, 'workspace_id' => 1, 'type' => 'supplier', 'status' => 'active',
        ]);
    }

    private function product(int $id, array $metadata): void
    {
        DB::table('master_records')->insert([
            'id' => $id, 'workspace_id' => 1, 'type' => 'product', 'name' => 'Catalog '.$id,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    private function line(?int $productId, ?int $supplierId, string $name, int $quantity, bool $removed = false): void
    {
        DB::table('flow_job_items')->insert([
            'flow_job_id' => 501, 'catalog_product_id' => $productId,
            'supplier_id' => $supplierId, 'product_name' => $name,
            'quantity' => $quantity, 'is_removed' => $removed,
        ]);
    }
}
