<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowLegacyItemModalRegressionTest extends TestCase
{
    public function test_workflow_modal_guards_relation_loaded_for_legacy_product_fallback_items(): void
    {
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));

        $this->assertStringContainsString("method_exists(\$firstItem, 'relationLoaded')", $modal);
        $this->assertStringNotContainsString("\$firstItem && \$firstItem->relationLoaded('supplier')", $modal);
    }
}
