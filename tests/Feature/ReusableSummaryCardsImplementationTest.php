<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReusableSummaryCardsImplementationTest extends TestCase
{
    public function test_my_tasks_and_clients_reuse_the_shared_summary_card_component(): void
    {
        $component = file_get_contents(resource_path('views/components/ui/summary-card.blade.php'));
        $myWork = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));
        $allTasks = file_get_contents(resource_path('views/livewire/board/index.blade.php'));
        $clients = \Tests\Support\AdministrationPhase7Source::clientsView();

        $this->assertStringContainsString("'displayValue' => null", $component);
        $this->assertStringContainsString("'clients' =>", $component);
        $this->assertStringContainsString("'orders' =>", $component);
        $this->assertStringContainsString("'money' =>", $component);

        foreach (['Created Today', 'Not Started', 'In Progress', 'Due This Week', 'Completed This Week', 'Needs Attention'] as $label) {
            $this->assertStringContainsString('label="'.$label.'"', $myWork);
        }
        $this->assertSame(6, substr_count($myWork, '<x-ui.summary-card '));

        foreach (['Created Today', 'Not Started', 'In Progress', 'Due This Week', 'Completed This Week', 'Needs Attention'] as $label) {
            $this->assertStringContainsString('label="'.$label.'"', $allTasks);
        }
        $this->assertSame(6, substr_count($allTasks, '<x-ui.summary-card '));

        foreach (['Total clients', 'Active Jobs', 'Needs attention', 'Outstanding'] as $label) {
            $this->assertStringContainsString('label="'.$label.'"', $clients);
        }
        $this->assertStringContainsString('ft-summary-card-grid-4', $clients);
    }

    public function test_my_task_card_filters_share_the_counter_definitions(): void
    {
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $service = file_get_contents(app_path('Services/MyWorkService.php'));

        $this->assertStringContainsString("private const METRIC_FILTERS = ['createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completedThisWeek', 'attention']", $component);
        $this->assertStringContainsString("'createdToday' => (int)", $service);
        $this->assertStringContainsString("'notStarted' => (int)", $service);
        $this->assertStringContainsString("'inProgress' => (int)", $service);
        $this->assertStringContainsString("'dueThisWeek' => (int)", $service);
        $this->assertStringContainsString("'completedThisWeek' => (int)", $service);
        $this->assertStringContainsString("'createdToday' => \$query->whereBetween('tasks.created_at'", $service);
        $this->assertStringContainsString("'completedThisWeek' => \$query", $service);
        $this->assertStringContainsString("->orWhereNull('tasks.assignee_id')", $service);
    }
}
