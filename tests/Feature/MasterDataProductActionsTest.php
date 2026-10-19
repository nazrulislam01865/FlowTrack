<?php

namespace Tests\Feature;

use Tests\TestCase;

class MasterDataProductActionsTest extends TestCase
{
    public function test_product_row_actions_use_dedicated_workspace_scoped_livewire_actions(): void
    {
        $view = \Tests\Support\AdministrationPhase7Source::masterDataView();
        $component = \Tests\Support\AdministrationPhase7Source::masterData();
        $actions = file_get_contents(resource_path('views/components/catalog/action-menu.blade.php'));

        $this->assertStringContainsString('<x-catalog.action-menu', $view);
        $this->assertStringContainsString('$wire.editProduct({{ $productId }})', $actions);
        $this->assertStringContainsString('$wire.deleteProduct({{ $productId }})', $actions);
        $this->assertStringContainsString("window.confirm('Delete this product?')", $actions);
        $this->assertStringContainsString("\$menuId = 'product-actions-'.\$productId", $actions);

        $this->assertStringContainsString('public function editProduct(int $id): void', $component);
        $this->assertStringContainsString('public function deleteProduct(int $id): void', $component);
        $this->assertStringContainsString("->ofType('product')", $component);
        $this->assertStringContainsString("session()->flash('success', 'Product deleted.')", $component);
    }
}
