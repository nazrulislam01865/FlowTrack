<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductFormTypographyContractTest extends TestCase
{
    public function test_product_form_uses_shared_form_typography_contract(): void
    {
        $view = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $forms = file_get_contents(resource_path('css/components/forms.css'));
        $adapter = file_get_contents(resource_path('css/modules/setup/master-data/product-form-typography.css'));

        $this->assertStringContainsString('ft-form-standard--product', $view);
        $this->assertStringContainsString('--ft-form-type-page-title-size', $forms);
        $this->assertStringContainsString('var(--ft-form-type-page-title-size', $adapter);
        $this->assertStringContainsString('var(--ft-form-type-label-size', $adapter);
        $this->assertStringContainsString('var(--ft-form-type-control-size', $adapter);
        $this->assertStringContainsString('var(--ft-form-type-helper-size', $adapter);
    }
}
