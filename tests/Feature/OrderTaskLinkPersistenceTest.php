<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class OrderTaskLinkPersistenceTest extends TestCase
{
    public function test_order_task_link_save_persists_before_the_inline_form_is_closed(): void
    {
        $service = file_get_contents(app_path('Services/TaskService.php'));
        $livewire = OrderPhase5Source::livewire();
        $taskDetail = OrderPhase5Source::taskDetailView();
        $taskRow = file_get_contents(resource_path('views/components/jobs/order-detail/task-row.blade.php'));
        $jobService = $this->jobServiceSource();

        $addStart = strpos($service, 'public function addExternalLink');
        $removeStart = strpos($service, 'public function removeExternalLink');
        $this->assertNotFalse($addStart);
        $this->assertNotFalse($removeStart);
        $addMethod = substr($service, $addStart, $removeStart - $addStart);

        $this->assertStringContainsString('$task->links()->create([', $addMethod);
        $this->assertStringContainsString('return $link->refresh();', $addMethod);
        $this->assertStringNotContainsString('$this->refreshJobState($task, $actor);', $addMethod);

        $this->assertStringContainsString('$task->links()->whereKey($link->id)->exists()', $livewire);
        $this->assertStringContainsString("'links.creator:id,name'", $livewire);
        $this->assertStringContainsString('private function hydrateLoadedTaskLinks(FlowJob $job): void', $jobService);
        $this->assertStringContainsString('TaskLink::query()', $jobService);
        $this->assertStringNotContainsString("setRelation('visibleTaskLinks'", $jobService);
        $this->assertStringContainsString("\$task->setRelation(", $jobService);
        $this->assertStringContainsString("'links'", $jobService);
        $this->assertStringContainsString('$task->setRelation(', $jobService);
        $this->assertStringContainsString('task-detail-link-{{ $taskLink->id }}', $taskDetail);
        $this->assertStringContainsString('External link ·', $taskDetail);
        $presenter = file_get_contents(app_path('Support/JobDetailPresenter.php'));
        $this->assertStringContainsString('public static function taskLinks(FlowJob $job, Task $task): Collection', $presenter);
        $this->assertStringNotContainsString("visibleTaskLinks", $presenter);
        $this->assertStringContainsString("relationLoaded('links')", $presenter);
        $this->assertStringContainsString('JobDetailPresenter::taskLinks($job, $task)', $taskRow);
        $this->assertStringContainsString('order-task-link-{{ $link->id }}', $taskRow);
        $this->assertStringContainsString('wire:submit.prevent="saveOverviewTaskLink({{ $task->id }})"', $taskRow);
    }
}
