<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderProductsSectionIsolationPerformanceTest extends TestCase
{
    public function test_order_products_are_loaded_by_an_isolated_livewire_component(): void
    {
        $overview = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));
        $component = file_get_contents(app_path('Livewire/Jobs/OrderProductsSection.php'));
        $view = file_get_contents(resource_path('views/livewire/jobs/order-products-section.blade.php'));

        $this->assertStringContainsString('<livewire:jobs.order-products-section', $overview);
        $this->assertStringNotContainsString('section="products"\n            method="loadDetailSection"', $overview);

        $this->assertStringContainsString('final class OrderProductsSection extends Component', $component);
        $this->assertStringContainsString('use ManagesOrderProducts;', $component);
        $this->assertStringContainsString('use ManagesMissingProductSupplier;', $component);
        $this->assertStringContainsString('if (! $this->ready)', $component);
        $this->assertStringContainsString('$orderQuery->loadOverviewProducts($job, $user);', $component);
        $this->assertStringNotContainsString('OrderDetailViewService', $component);

        $this->assertStringContainsString('method="loadProductsSection"', $view);
        $this->assertStringContainsString('queue-group="order-detail-{{ $orderId }}"', $view);
        $this->assertStringContainsString(':queue-priority="10"', $view);
        $this->assertStringContainsString('<x-jobs.order-detail.products', $view);
        $this->assertStringContainsString('<x-catalog.missing-product-supplier-modal', $view);
    }

    public function test_products_keep_the_existing_product_action_component_and_method_contracts(): void
    {
        $products = file_get_contents(resource_path('views/components/jobs/order-detail/products.blade.php'));
        $concern = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderProducts.php'));

        $this->assertStringContainsString('edit-method="openEditOrderProductModal"', $products);
        $this->assertStringContainsString('remove-method="removeJobItem"', $products);
        $this->assertStringContainsString('restore-method="restoreJobItem"', $products);
        $this->assertStringContainsString('save-method="saveJobProduct({{ $job->id }})"', $products);

        foreach ([
            'openEditOrderProductModal',
            'saveEditOrderProductModal',
            'openAddJobProductForm',
            'saveJobProduct',
            'removeJobItem',
            'restoreJobItem',
        ] as $method) {
            $this->assertStringContainsString('function '.$method.'(', $concern);
        }
    }
}
