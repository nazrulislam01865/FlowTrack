<?php

namespace Tests\Feature;

use Tests\TestCase;

class ListCreatedDateRangeFilterImplementationTest extends TestCase
{
    public function test_inquiry_and_order_lists_share_created_date_range_filter(): void
    {
        $dateComponent = file_get_contents(resource_path('views/components/ui/date-range.blade.php'));
        $inquiryView = $this->inquiryViewSource();
        $orderView = file_get_contents(resource_path('views/livewire/orders/index.blade.php'));
        $orderFilters = file_get_contents(resource_path('views/components/orders/list/filters.blade.php'));
        $inquiryComponent = $this->inquiryLivewireSource();
        $orderComponent = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $inquiryService = $this->inquiryServiceSource();
        $jobService = $this->jobServiceSource();
        $workspaceSettings = file_get_contents(app_path('Services/WorkspaceSettingsService.php'));

        $this->assertStringContainsString('Date from', $dateComponent);
        $this->assertStringNotContainsString('ft-date-range-filter-title', $dateComponent);
        $this->assertStringContainsString('Date to', $dateComponent);
        $this->assertStringContainsString('type="date"', $dateComponent);
        $this->assertStringContainsString('lang="en-GB"', $dateComponent);
        $this->assertStringContainsString('<x-ui.date-range', $inquiryView);
        $this->assertStringContainsString('class="ft-inquiry-date-range"', $inquiryView);
        $this->assertStringContainsString('from-property="pendingDateFrom"', $inquiryView);
        $this->assertStringContainsString('to-property="pendingDateTo"', $inquiryView);
        $this->assertStringContainsString('wire:click="applyFilters"', $inquiryView);
        $hideCompletedPosition = strpos($inquiryView, '<label class="completed-toggle');
        $dateRangePosition = strpos($inquiryView, '<x-ui.date-range');
        $clearFilterPosition = strpos($inquiryView, 'class="chip ft-inquiry-clear-filter"');
        $this->assertNotFalse($hideCompletedPosition);
        $this->assertNotFalse($dateRangePosition);
        $this->assertNotFalse($clearFilterPosition);
        $this->assertGreaterThan($hideCompletedPosition, $dateRangePosition);
        $this->assertGreaterThan($dateRangePosition, $clearFilterPosition);
        $this->assertStringContainsString(':date-from="$dateFrom"', $orderView);
        $this->assertStringContainsString(':date-to="$dateTo"', $orderView);
        $this->assertStringContainsString('<x-ui.date-range', $orderFilters);
        $this->assertStringContainsString('from-property="dateFrom"', $orderFilters);
        $this->assertStringContainsString('to-property="dateTo"', $orderFilters);

        foreach ([$inquiryComponent, $orderComponent] as $component) {
            $this->assertStringContainsString("public string \$dateFrom = '';", $component);
            $this->assertStringContainsString("public string \$dateTo = '';", $component);
            $this->assertStringContainsString('public function updatedDateFrom(): void', $component);
            $this->assertStringContainsString('public function updatedDateTo(): void', $component);
            $this->assertStringContainsString("clearListFiltersExcept('dateRange')", $component);
        }

        $this->assertStringContainsString("'date_from' => \$this->dateFrom", $inquiryComponent);
        $this->assertStringContainsString("'date_to' => \$this->dateTo", $inquiryComponent);
        $this->assertStringContainsString("where('inquiries.created_at', '>=', \$dateFromUtc)", $inquiryService);
        $this->assertStringContainsString("where('inquiries.created_at', '<=', \$dateToUtc)", $inquiryService);
        $this->assertStringContainsString("where('flow_jobs.created_at', '>=', \$dateFromUtc)", $jobService);
        $this->assertStringContainsString("where('flow_jobs.created_at', '<=', \$dateToUtc)", $jobService);
        $this->assertStringContainsString('public function localDateRangeUtcBounds', $workspaceSettings);
    }
}
