<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderListClientProductColumnTest extends TestCase
{
    public function test_overview_client_product_column_is_compact_and_user_friendly(): void
    {
        $view = file_get_contents(resource_path('views/components/orders/list/table.blade.php'));
        $css = file_get_contents(resource_path('css/modules/orders/list.css'));

        $this->assertStringContainsString("'orders-modern-table--overview' => \$sequence === 0", $view);
        $this->assertStringContainsString('class="client-product-column" data-label="Client & Product"', $view);
        $this->assertStringContainsString('<b title="{{ $clientLabel }}">', $view);
        $this->assertStringContainsString('<small title="{{ $productSummary }}">', $view);

        $this->assertStringContainsString('.orders-modern-table--overview .client-product-column', $css);
        $this->assertStringContainsString('width: 400px;', $css);
        $this->assertStringContainsString('max-width: 400px;', $css);
        $this->assertStringContainsString('max-width: calc(100% - 43px);', $css);
        $this->assertStringContainsString('text-overflow: ellipsis', $css);
    }
}
