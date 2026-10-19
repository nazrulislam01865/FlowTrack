<?php

namespace Tests\Feature;

use Tests\TestCase;

class MasterDataCreateProductPrototypeTest extends TestCase
{
    public function test_create_product_page_uses_the_shared_catalogue_components(): void
    {
        $view = \Tests\Support\AdministrationPhase7Source::masterDataView();
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $component = \Tests\Support\AdministrationPhase7Source::masterData();

        $this->assertStringContainsString('<x-catalog.product-form', $view);
        $this->assertStringContainsString("{{ \$isEdit ? 'Edit product' : 'Create product' }}", $form);
        $this->assertStringContainsString('Generated automatically after the product is created.', $form);
        $this->assertStringContainsString('label="Main category"', $form);
        $this->assertStringContainsString('label="Product category"', $form);
        $this->assertStringContainsString('label="Subcategory"', $form);
        $this->assertStringContainsString('Drop image or <span>browse</span>', $form);
        $this->assertStringContainsString("openCategoryCreator('product')", $form);

        $this->assertStringContainsString('public function openCategoryCreator(string $level): void', $component);
        $this->assertStringContainsString('public function createProductCategory(): void', $component);
        $this->assertStringContainsString("\$service->nextCode('product_category')", $component);
    }
}
