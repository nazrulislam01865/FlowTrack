<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class ProgressivePageRenderingTest extends TestCase
{
    public function test_all_tasks_server_renders_the_paginated_task_page_without_job_board_bootstrap(): void
    {
        $component = file_get_contents(app_path('Livewire/Board/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/board/index.blade.php'));

        $this->assertStringContainsString('public string $mode = \'tasks\';', $component);
        $this->assertStringContainsString('return view(\'livewire.board.index\', $this->taskPackBoardData(auth()->user()));', $component);
        $this->assertStringNotContainsString('wire:init="loadBoardCards"', $view);
        $this->assertStringNotContainsString('livewire.shared.board-cards-placeholder', $view);
        $this->assertStringContainsString('<h1>All Tasks</h1>', $view);
        $this->assertStringNotContainsString('Job Board', $view);
    }

    public function test_my_work_server_renders_a_bounded_first_page_instead_of_waiting_for_wire_init(): void
    {
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));
        $service = file_get_contents(app_path('Services/MyWorkService.php'));

        $this->assertStringNotContainsString('wire:init="loadMyWorkTasks"', $view);
        $this->assertStringNotContainsString('public bool $tasksReady', $component);
        $this->assertStringContainsString('MyWorkService::JOBS_PER_PAGE', $component);
        $this->assertStringContainsString('Paginate Jobs, never individual tasks.', $service);
        $this->assertStringContainsString('->paginate(', $service);
    }

    public function test_create_job_uses_viewport_loaded_sections(): void
    {
        $component = OrderPhase5Source::livewire();
        $view = file_get_contents(resource_path('views/components/jobs/create.blade.php'));
        $placeholder = file_get_contents(resource_path('views/components/jobs/create-section-placeholder.blade.php'));
        $products = OrderPhase5Source::createProductsView();

        $this->assertStringContainsString('function loadCreateSection(string $section)', $component);
        $this->assertStringContainsString('createCatalogReady', $component);
        $this->assertStringContainsString('createAssignmentReady', $component);
        $this->assertStringContainsString('createWorkflowReady', $component);
        $this->assertStringContainsString("@include('components.jobs.create-products')", $view);
        $this->assertStringContainsString('@if($catalogReady && $canUseOrderProductSelector)', $products);
        $this->assertStringContainsString('@elseif(!$catalogReady)', $products);
        $this->assertStringContainsString('@if($assignmentReady)', $view);
        $this->assertStringContainsString('@if($workflowReady)', $view);
        $this->assertStringContainsString('IntersectionObserver', $placeholder);
    }
}
