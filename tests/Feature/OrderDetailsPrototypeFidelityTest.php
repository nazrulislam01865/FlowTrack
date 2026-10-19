<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderDetailsPrototypeFidelityTest extends TestCase
{
    public function test_order_details_matches_the_supplied_prototype_structure_without_putting_workflow_logic_in_blade(): void
    {
        $header = file_get_contents(resource_path('views/components/jobs/order-detail/header.blade.php'));
        $summary = file_get_contents(resource_path('views/components/jobs/order-detail/summary.blade.php'));
        $overview = file_get_contents(resource_path('views/components/jobs/order-detail/overview-card.blade.php'));
        $planning = file_get_contents(resource_path('views/components/jobs/order-detail/planning.blade.php'));
        $shipping = file_get_contents(resource_path('views/components/jobs/order-detail/shipping.blade.php'));
        $products = file_get_contents(resource_path('views/components/jobs/order-detail/products.blade.php'));
        $workflow = file_get_contents(resource_path('views/components/jobs/order-detail/workflow.blade.php'));
        $activity = file_get_contents(resource_path('views/components/jobs/order-detail/activity.blade.php'));
        $css = $this->orderDetailCss();
        $service = $this->jobServiceSource();

        $this->assertStringContainsString('detail-header', $header);
        $this->assertStringContainsString('order-commandbar', $header);
        $this->assertStringContainsString('Shipment urgency', $header);
        $this->assertStringContainsString('aria-label="Edit order owner"', $header);
        $this->assertStringContainsString('Flag order', $header);
        $this->assertStringContainsString('Cancel order', $header);

        $this->assertStringContainsString('summary-grid', $summary);
        $this->assertStringContainsString('Current stage', $summary);
        $this->assertStringContainsString('Overall progress', $summary);
        $this->assertStringContainsString('Next required action', $summary);

        $this->assertStringContainsString('ft-order-rich-overview', $overview);
        $this->assertStringContainsString('data-rich-text', $overview);
        $this->assertStringContainsString('Planning &amp; ownership', $planning);
        $this->assertStringContainsString('Shipping address', $shipping);
        $this->assertStringContainsString('Products &amp; quantities', $products);
        $this->assertStringContainsString('removed-product-row', $products);

        $this->assertStringContainsString('Order process &amp; tasks', $workflow);
        $this->assertStringContainsString('stages · status changes save automatically · conditional tasks appear only when required', $workflow);
        $this->assertStringContainsString('Activity', $activity);

        $this->assertStringContainsString('.ft-order-prototype-detail .workflow', $css);
        $this->assertStringContainsString('.ft-order-prototype-detail .task', $css);
        $this->assertStringContainsString('.ft-order-prototype-detail .summary-grid', $css);
        $this->assertStringContainsString('.ft-order-prototype-detail .overview-grid', $css);

        // N+1 protection: relationships required by the prototype are eager loaded once.
        $this->assertStringContainsString("'items.catalogProduct.parent:id,name,code,type,status'", $service);
        $this->assertStringContainsString("'items.supplier:id,name,code,type,status'", $service);
        $this->assertStringContainsString("'documents.uploader:id,name,profile_image_path'", $service);
        $this->assertStringContainsString("'assignee:id,name,profile_image_path'", $service);
    }
}
