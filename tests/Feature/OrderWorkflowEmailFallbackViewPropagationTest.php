<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowEmailFallbackViewPropagationTest extends TestCase
{
    public function test_jobs_order_detail_views_propagate_email_fallback_state_safely(): void
    {
        $rootView = file_get_contents(resource_path('views/livewire/jobs/index.blade.php'));
        $detailView = file_get_contents(resource_path('views/components/jobs/detail.blade.php'));
        $overviewView = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));

        $this->assertStringContainsString(':order-workflow-email-fallback="$orderWorkflowEmailFallback"', $rootView);
        $this->assertStringContainsString(':order-workflow-email-fallback-message="$orderWorkflowEmailFallbackMessage"', $rootView);
        $this->assertStringContainsString(':order-workflow-email-fallback-attempts="$orderWorkflowEmailFallbackAttempts"', $rootView);

        $this->assertStringContainsString("'orderWorkflowEmailFallback'=>false", $detailView);
        $this->assertStringContainsString("'orderWorkflowEmailFallbackMessage'=>''", $detailView);
        $this->assertStringContainsString("'orderWorkflowEmailFallbackAttempts'=>0", $detailView);
        $this->assertStringContainsString(':order-workflow-email-fallback="$orderWorkflowEmailFallback"', $detailView);

        $this->assertStringContainsString("'orderWorkflowEmailFallback' => false", $overviewView);
        $this->assertStringContainsString("'orderWorkflowEmailFallbackMessage' => ''", $overviewView);
        $this->assertStringContainsString("'orderWorkflowEmailFallbackAttempts' => 0", $overviewView);
        $this->assertStringContainsString(':email-fallback="$orderWorkflowEmailFallback"', $overviewView);
    }
}
