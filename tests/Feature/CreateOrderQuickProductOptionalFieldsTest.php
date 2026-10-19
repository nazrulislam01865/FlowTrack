<?php

namespace Tests\Feature;

use Tests\Support\OrderPhase5Source;
use Tests\TestCase;

class CreateOrderQuickProductOptionalFieldsTest extends TestCase
{
    public function test_order_quick_create_generates_missing_code_and_allows_missing_default_supplier(): void
    {
        $component = OrderPhase5Source::livewire();
        $view = OrderPhase5Source::createProductsView();
        $createComponent = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $livewireView = file_get_contents(resource_path('views/livewire/jobs/index.blade.php'));

        $this->assertStringContainsString("'newProductCode' => ['nullable', 'string', 'max:40'", $component);
        $this->assertStringContainsString('$productCode = $service->nextCode(\'product\');', $component);
        $this->assertStringContainsString("'newProductSupplierId' => [\n                'nullable'", $component);
        $this->assertStringContainsString("'metadata' => filled(\$data['newProductSupplierId'] ?? null)", $component);
        $this->assertStringContainsString('SKU / Product code <em>Optional</em>', $view);
        $this->assertStringContainsString('Default supplier <em>Optional</em>', $view);
        $this->assertStringContainsString(':initial-options="$newProductSupplierOptions"', $view);
        $this->assertStringContainsString("'suppliers'", $component);
        $this->assertStringContainsString("'newProductSupplierOptions' => \$newProductSupplierOptions", $component);
        $this->assertStringContainsString("'newProductSupplierOptions'=>collect()", $createComponent);
        $this->assertStringContainsString(':new-product-supplier-options="$newProductSupplierOptions"', $livewireView);
    }
}
