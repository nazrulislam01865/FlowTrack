<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderInquiryCreatedTodayCompletedFiltersTest extends TestCase
{
    public function test_order_and_inquiry_lists_expose_created_today_and_completed_filters_and_completed_cards(): void
    {
        $orderFilters = file_get_contents(resource_path('views/components/orders/list/filters.blade.php'));
        $orderHeader = file_get_contents(resource_path('views/components/orders/list/header-and-stages.blade.php'));
        $orderComponent = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $orderService = file_get_contents(app_path('Services/LegacyJobService.php'));
        $orderPrototype = file_get_contents(app_path('Services/OrderListPrototypeService.php'));
        $inquiryView = file_get_contents(resource_path('views/livewire/inquiries/sections/list.blade.php'));
        $inquiryComponent = file_get_contents(app_path('Livewire/Inquiries/Index.php'));
        $inquiryList = file_get_contents(app_path('Livewire/Inquiries/Concerns/ManagesInquiryList.php'));
        $inquiryService = file_get_contents(app_path('Services/LegacyInquiryService.php'));

        $this->assertStringContainsString("wire:click=\"setMetricFilter('createdToday')\"", $orderFilters);
        $this->assertStringContainsString("wire:click=\"setMetricFilter('completed')\"", $orderFilters);
        $this->assertStringContainsString('label="Completed"', $orderHeader);
        $this->assertStringContainsString("'completed'", $orderComponent);
        $this->assertStringContainsString("'completed' => \$this->applyCompletedOrderScope(\$query)", $orderService);
        $this->assertStringContainsString('private function applyCompletedOrderScope', $orderService);
        $this->assertStringContainsString("! in_array((string) (\$filters['metric'] ?? ''), ['completedThisWeek', 'completed'], true)", $orderPrototype);

        $this->assertStringContainsString("wire:click=\"setMetricFilter('createdToday')\"", $inquiryView);
        $this->assertStringContainsString("wire:click=\"setMetricFilter('completed')\"", $inquiryView);
        $this->assertStringContainsString('label="Completed"', $inquiryView);
        $this->assertStringContainsString("'completed' => 0", $inquiryComponent);
        $this->assertStringContainsString("'completed' => \$this->applyCompletedListScope(\$query)", $inquiryService);
        $this->assertStringContainsString("'completed'", $inquiryList);
    }

    public function test_list_card_counts_are_filter_aware_and_use_bounded_aggregate_queries(): void
    {
        $orderIndex = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $orderQuery = file_get_contents(app_path('Queries/Orders/OrderListQuery.php'));
        $orderPrototype = file_get_contents(app_path('Services/OrderListPrototypeService.php'));
        $inquiryPageData = file_get_contents(app_path('Livewire/Inquiries/Concerns/BuildsInquiryPageData.php'));
        $inquiryList = file_get_contents(app_path('Livewire/Inquiries/Concerns/ManagesInquiryList.php'));
        $inquiryQuery = file_get_contents(app_path('Queries/Inquiries/InquiryListQuery.php'));
        $inquiryService = file_get_contents(app_path('Services/LegacyInquiryService.php'));

        $this->assertStringContainsString('stagesForFilters($user, $filters)', $orderIndex);
        $this->assertStringContainsString('completedCount($user, $filters, $stages)', $orderIndex);
        $this->assertStringContainsString('public function stagesForFilters(User $actor, array $filters): Collection', $orderQuery);
        $this->assertStringContainsString('public function completedCount(User $actor, array $filters, Collection $stages): int', $orderQuery);
        $this->assertStringContainsString('private function stageCardsFromCountQuery(Builder $countQuery): Collection', $orderPrototype);
        $this->assertStringContainsString('private function buildListQuery(', $orderPrototype);

        $this->assertStringContainsString('$filters = $this->currentInquiryListFilters();', $inquiryPageData);
        $this->assertStringContainsString('$this->refreshInquiryListMetrics();', $inquiryList);
        $this->assertStringContainsString('public function metrics(User $actor, array $filters = []): array', $inquiryQuery);
        $this->assertStringContainsString('public function metrics(User $user, array $filters = []): array', $inquiryService);
        $this->assertStringContainsString('COALESCE(SUM(CASE WHEN', $inquiryService);
        $this->assertStringNotContainsString("'completed' => (int) \$this->applyCompletedListScope(clone \$base)->count()", $inquiryService);
    }
}
