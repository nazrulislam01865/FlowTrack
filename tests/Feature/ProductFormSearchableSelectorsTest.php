<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductFormSearchableSelectorsTest extends TestCase
{
    public function test_product_form_uses_shared_searchable_selectors_and_attachment_actions(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $creator = file_get_contents(resource_path('views/components/catalog/category-creator.blade.php'));
        $upload = file_get_contents(resource_path('views/components/catalog/file-upload.blade.php'));
        $component = \Tests\Support\AdministrationPhase7Source::masterData();

        $this->assertSame(5, substr_count($form, '<x-ui.search-select'));
        $this->assertSame(2, substr_count($form, '<x-ui.multi-select'));
        $this->assertSame(2, substr_count($creator, '<x-ui.search-select'));
        $this->assertStringNotContainsString('<select', $form);
        $this->assertStringNotContainsString('<select', $creator);
        $this->assertStringContainsString('placeholder="Search and select clients"', $form);
        $this->assertStringContainsString('property="productSupplierId"', $form);
        $this->assertStringContainsString('property="productSupplierIds"', $form);
        $this->assertStringContainsString('type="suppliers"', $form);
        $this->assertStringContainsString('Preview', $upload);
        $this->assertStringContainsString('removeCurrentAction', $upload);
        $this->assertStringContainsString('public function removeProductCertificate(): void', $component);
        $this->assertStringContainsString('public function removeProductTemplate(): void', $component);
    }
}
