<?php

namespace Tests\Feature;

use App\Services\MasterDataService;
use App\Support\MasterColor;
use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class MasterDataExtendedColorTest extends TestCase
{
    public function test_priority_and_inquiry_task_status_are_master_color_types(): void
    {
        $this->assertContains('priority', MasterDataService::COLOR_TYPES);
        $this->assertContains('inquiry_task_status', MasterDataService::COLOR_TYPES);
        $this->assertSame('#DC2626', MasterColor::defaultFor('priority', 'Critical'));
        $this->assertSame('#D97706', MasterColor::defaultFor('inquiry_task_status', 'Waiting'));
    }

    public function test_master_data_ui_and_main_surfaces_use_master_colors(): void
    {
        $masterView = \Tests\Support\AdministrationPhase7Source::masterDataView();
        $inquiryView = $this->inquiryViewSource();
        $dashboardView = file_get_contents(resource_path('views/livewire/dashboard/index.blade.php'));
        $taskDetail = OrderPhase5Source::taskDetailView();
        $taskCard = file_get_contents(resource_path('views/components/board/task-card.blade.php'));

        $this->assertStringContainsString('MasterDataService::COLOR_TYPES', $masterView);
        $this->assertStringContainsString("displayColorFor('inquiry_task_status'", $inquiryView);
        $this->assertStringContainsString("displayColorFor('priority'", $inquiryView);
        $this->assertStringContainsString('inquiryStatusColor(', $dashboardView);
        $this->assertStringContainsString("displayColorFor('priority'", $taskDetail);
        $this->assertStringContainsString("displayColorFor('priority'", $taskCard);
    }

    public function test_follow_up_migration_backfills_existing_priority_and_inquiry_status_rows(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_11_044500_enable_colors_for_priorities_and_inquiry_statuses.php'));

        $this->assertStringContainsString("['priority', 'inquiry_status']", $migration);
        $this->assertStringContainsString('MasterColor::defaultFor', $migration);
    }
}
