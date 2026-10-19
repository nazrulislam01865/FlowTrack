<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase8ApplicationBoundaryTest extends TestCase
{
    public function test_giant_service_names_are_thin_compatibility_facades(): void
    {
        foreach ([
            'JobService' => 'LegacyJobService',
            'InquiryService' => 'LegacyInquiryService',
            'DashboardService' => 'LegacyDashboardService',
        ] as $facade => $legacy) {
            $source = file_get_contents(app_path("Services/{$facade}.php"));
            $this->assertStringContainsString('extends '.$legacy, $source);
            $this->assertLessThanOrEqual(40, substr_count($source, "\n") + 1);
        }
    }

    public function test_focused_domain_services_and_dtos_exist(): void
    {
        foreach ([
            'Services/Orders/OrderReadService.php',
            'Services/Orders/OrderLifecycleService.php',
            'Services/Orders/OrderItemService.php',
            'Services/Orders/OrderWorkflowService.php',
            'Services/Inquiries/InquiryReadService.php',
            'Services/Inquiries/InquiryLifecycleService.php',
            'Services/Inquiries/InquiryTaskService.php',
            'Services/Inquiries/InquiryDocumentService.php',
            'Services/Dashboard/DashboardOverviewService.php',
            'Services/Dashboard/DashboardMentionService.php',
            'DTOs/Orders/OrderCreateData.php',
            'DTOs/Inquiries/InquiryCreateData.php',
            'DTOs/Dashboard/DashboardFilterData.php',
        ] as $relative) {
            $this->assertFileExists(app_path($relative));
        }
    }

    public function test_dashboard_livewire_uses_query_and_action_boundaries(): void
    {
        $source = file_get_contents(app_path('Livewire/Dashboard/Index.php'))
            . file_get_contents(app_path('Livewire/Dashboard/Secondary.php'))
            . file_get_contents(app_path('Livewire/Dashboard/TaggedComments.php'));

        $this->assertStringNotContainsString('DashboardService::class', $source);
        $this->assertStringContainsString('DashboardPrimaryQuery::class', $source);
        $this->assertStringContainsString('DashboardSecondaryQuery::class', $source);
        $this->assertStringContainsString('MarkDashboardMentionsRead::class', $source);
    }
}
