<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderRequiredDocumentsAndMyWorkSearchPerformanceTest extends TestCase
{
    public function test_order_required_document_presenter_never_runs_task_queries_in_its_item_loop(): void
    {
        $presenter = file_get_contents(app_path('Support/JobDetailPresenter.php'));

        $this->assertStringNotContainsString('Task::query()', $presenter);
        $this->assertStringContainsString("\$task->relationLoaded('documents')", $presenter);
        $this->assertStringContainsString('if (!$task) continue;', $presenter);
    }

    public function test_order_detail_tabs_eager_load_tasks_and_documents_before_presenting_requirements(): void
    {
        $service = $this->jobServiceSource();

        $this->assertStringContainsString("if (\$tab === 'overview')", $service);
        $this->assertStringContainsString("if (\$tab === 'workflow')", $service);
        $this->assertGreaterThanOrEqual(3, substr_count($service, "'tasks' => fn (\$query)"));
        $this->assertGreaterThanOrEqual(3, substr_count($service, "'documents"));
    }

    public function test_my_work_requires_three_characters_for_broad_search_but_keeps_reference_prefixes_fast(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringContainsString('if ($length >= 3) return true;', $service);
        $this->assertStringContainsString("preg_match('/^(JO|TS|TA|OR)\$/i'", $service);
        $this->assertStringContainsString('$referencePrefixOnly = mb_strlen($search) < 3', $service);
        $this->assertStringContainsString("wire:model.live.debounce.650ms=\"search\"", $view);
        $this->assertStringContainsString('! app(MyWorkService::class)->searchIsUsable($this->search)', $component);
    }
}
