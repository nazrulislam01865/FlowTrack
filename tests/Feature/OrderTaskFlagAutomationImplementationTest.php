<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class OrderTaskFlagAutomationImplementationTest extends TestCase
{
    public function test_order_status_task_flag_and_order_flag_use_separate_master_catalogues(): void
    {
        $master = file_get_contents(app_path('Services/MasterDataService.php'));
        $component = \Tests\Support\AdministrationPhase7Source::masterData();
        $view = \Tests\Support\AdministrationPhase7Source::masterDataView();

        $this->assertStringContainsString("'order_task_status' => 'Order Task Statuses'", $master);
        $this->assertStringContainsString("'order_task_flag' => 'Order Task Flags'", $master);
        $this->assertStringContainsString("'order_flag' => 'Order Flags'", $master);
        $this->assertStringContainsString("\$metadata['order_task_flag_id']", $component);
        $this->assertStringContainsString("\$metadata['order_flag_id']", $component);
        $this->assertStringContainsString('Automatic Order Task Flag', $view);
        $this->assertStringContainsString('Parent Order Flag *', $view);
    }

    public function test_order_tasks_and_orders_store_the_automatic_flags_in_separate_columns(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_15_111500_separate_order_task_statuses_and_flags.php'));
        $task = file_get_contents(app_path('Models/Task.php'));
        $job = file_get_contents(app_path('Models/FlowJob.php'));

        $this->assertStringContainsString("foreignId('order_task_status_id')", $migration);
        $this->assertStringContainsString("foreignId('order_task_flag_id')", $migration);
        $this->assertStringContainsString("foreignId('order_flag_id')", $migration);
        $this->assertStringContainsString("'order_task_status_id'", $task);
        $this->assertStringContainsString("'order_task_flag_id'", $task);
        $this->assertStringContainsString("'order_flag_id'", $job);
    }

    public function test_overdue_flag_overrides_status_mapping_and_parent_order_flag_is_persisted(): void
    {
        $service = file_get_contents(app_path('Services/OrderTaskFlagService.php'));
        $console = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString("taskFlagBySystemKey('overdue')", $service);
        $this->assertStringContainsString("'order_task_flag_id' => \$flag?->id", $service);
        $this->assertStringContainsString("'order_flag_id' => \$orderFlagId", $service);
        $this->assertStringContainsString('flowtrack:sync-order-flags', $console);
        $this->assertStringContainsString('->everyFiveMinutes()', $console);
        $this->assertStringContainsString('->withoutOverlapping()', $console);
        $this->assertStringContainsString('->onOneServer()', $console);
    }


    public function test_due_transition_sync_is_not_invoked_from_normal_read_paths(): void
    {
        $readPaths = [
            app_path('Services/LegacyJobService.php'),
            app_path('Services/LegacyDashboardService.php'),
            app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'),
        ];

        foreach ($readPaths as $path) {
            $this->assertStringNotContainsString(
                'syncDueTransitions(',
                file_get_contents($path),
                'Global due-transition synchronization must remain outside HTTP/read rendering paths: '.$path,
            );
        }

        $console = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('syncDueTransitions(true)', $console);
    }

    public function test_order_task_ui_no_longer_offers_manual_flag_selection(): void
    {
        $view = OrderPhase5Source::taskDetailView();
        $table = file_get_contents(resource_path('views/components/jobs/table.blade.php'));

        $this->assertStringContainsString('Automatic flag', $view);
        $this->assertStringContainsString('Overdue overrides the status mapping', $view);
        $this->assertStringContainsString("displayColorFor('order_flag', \$automaticFlag)", $table);
        $this->assertStringNotContainsString("displayColorFor('task_flag', \$flag)", $table);
    }
}
