<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use App\Models\User;
use App\Services\FilterOptionService;
use App\Services\ProductCatalogService;
use Mockery;
use Tests\TestCase;

class CreateOrderLinkedSupplierSelectorTest extends TestCase
{
    public function test_create_order_supplier_picker_shows_only_suppliers_linked_to_the_selected_product(): void
    {
        $this->bindProductSuppliers(17, [
            $this->supplier(10, 'Alpha Supplies', 'AL'),
            $this->supplier(20, 'Beta Manufacturing', 'BA'),
        ]);

        $service = app(FilterOptionService::class);
        $user = $this->allowedUser();

        $page = $service->searchPage($user, 'suppliers', 'create-order-product-supplier', '', 1, 20, ['20', '999'], ['product_id' => 17]);
        $this->assertSame(['10', '20'], $page->items->pluck('id')->all());
        $this->assertSame(['20'], $page->selectedItems->pluck('id')->all());
        $this->assertSame(['Alpha Supplies', 'Beta Manufacturing'], $page->items->pluck('meta')->all());
        $this->assertFalse($page->hasMore);
    }

    public function test_single_linked_supplier_and_search_do_not_include_other_supplier_records(): void
    {
        $this->bindProductSuppliers(18, [$this->supplier(30, 'Unique Supplier', 'UN')]);

        $page = app(FilterOptionService::class)->searchPage(
            $this->allowedUser(), 'suppliers', 'create-order-product-supplier',
            'Unique', 1, 20, ['500'], ['product_id' => 18]
        );

        $this->assertSame(['30'], $page->items->pluck('id')->all());
        $this->assertSame([], $page->selectedItems->all());
    }

    public function test_create_order_supplier_picker_requires_a_product_target(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(FilterOptionService::class)->searchPage(
            $this->allowedUser(), 'suppliers', 'create-order-product-supplier',
            '', 1, 20, [], []
        );
    }

    private function bindProductSuppliers(int $productId, array $suppliers): void
    {
        $product = new MasterRecord();
        $product->forceFill(['id' => $productId, 'type' => 'product', 'name' => 'Selected Product']);

        $catalog = Mockery::mock(ProductCatalogService::class);
        $catalog->shouldReceive('findActiveProductOrFail')->once()->with($productId)->andReturn($product);
        $catalog->shouldReceive('allSuppliersForProducts')->once()
            ->andReturn(collect([$productId => collect($suppliers)]));
        $this->app->instance(ProductCatalogService::class, $catalog);
    }

    private function allowedUser(): User
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('canModule')->with('jobs', 'create')->andReturn(true);
        $user->shouldReceive('canModule')->with('catalog_products', 'view')->andReturn(true);

        return $user;
    }

    private function supplier(int $id, string $name, string $shortCode): MasterRecord
    {
        $supplier = new MasterRecord();
        $supplier->forceFill([
            'id' => $id,
            'type' => 'supplier',
            'name' => $name,
            'metadata' => ['short_code' => $shortCode],
        ]);

        return $supplier;
    }
}
