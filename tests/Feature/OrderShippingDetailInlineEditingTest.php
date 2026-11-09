<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class OrderShippingDetailInlineEditingTest extends TestCase
{
    public function test_order_detail_shipping_section_is_responsive_and_inline_editable(): void
    {
        $overview = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));
        $planning = file_get_contents(resource_path('views/components/jobs/order-detail/planning.blade.php'));
        $shipping = file_get_contents(resource_path('views/components/jobs/order-detail/shipping.blade.php'));
        $products = file_get_contents(resource_path('views/components/jobs/order-detail/products.blade.php'));
        $jobs = OrderPhase5Source::livewire();
        $service = $this->jobServiceSource();
        $css = $this->orderDetailCss();

        $this->assertStringContainsString('<x-jobs.order-detail.planning', $overview);
        $this->assertStringContainsString('<x-jobs.order-detail.shipping', $overview);
        $this->assertStringContainsString('<livewire:jobs.order-products-section', $overview);
        $productsSection = file_get_contents(resource_path('views/livewire/jobs/order-products-section.blade.php'));
        $this->assertStringContainsString('<x-jobs.order-detail.products', $productsSection);
        $this->assertStringContainsString('Planning &amp; ownership', $planning);
        $this->assertStringContainsString('Shipping address', $shipping);
        $productsCard = file_get_contents(resource_path('views/components/catalog/detail-products-card.blade.php'));
        $this->assertStringContainsString('<x-catalog.detail-products-card', $products);
        $this->assertStringContainsString("'title' => 'Products & quantities'", $productsCard);

        $this->assertStringContainsString('shipping_address', $shipping);
        $this->assertStringContainsString('shipping_phone_country_code', $service);
        $this->assertStringContainsString('shipping_phone', $shipping);
        $this->assertStringContainsString('shipping_postal_code', $shipping);
        $this->assertStringContainsString('updateJobShippingDetails', $shipping);
        $this->assertStringContainsString('@media(max-width:1180px)', $css);
        $this->assertStringContainsString('.ft-order-overview-grid', $css);
        $this->assertMatchesRegularExpression('/#\[Json\]\s+public function updateJobShippingDetails\b/', $jobs);
    }

}
