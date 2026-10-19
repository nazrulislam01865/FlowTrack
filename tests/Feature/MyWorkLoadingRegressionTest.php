<?php

namespace Tests\Feature;

use Tests\TestCase;

class MyWorkLoadingRegressionTest extends TestCase
{
    public function test_my_work_does_not_start_a_second_metrics_request_after_first_paint(): void
    {
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringNotContainsString('wire:init="loadMetrics"', $view);
        $this->assertStringNotContainsString('my-work-refresh-metrics', $view);
        $this->assertStringContainsString('$this->metrics = $service->metrics($user);', $component);
    }

    public function test_updating_indicator_is_bound_to_real_livewire_targets(): void
    {
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringContainsString('wire:loading.delay.long', $view);
        $this->assertStringContainsString('wire:loading.class="is-loading"', $view);
        $this->assertStringContainsString('wire:loading.remove', $view);
    }

    public function test_my_work_metrics_use_direct_index_friendly_aggregate(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));

        $this->assertStringContainsString("DB::table('tasks')", $service);
        $this->assertStringContainsString("->join('flow_jobs as my_work_metric_jobs'", $service);
        $this->assertStringContainsString("->join('clients as my_work_metric_clients'", $service);
        $this->assertStringContainsString("DB::table('activities')", $service);
        $this->assertStringContainsString("->where('event', 'task.comment')", $service);
        $this->assertStringContainsString("->leftJoinSub(\$taskMentions, 'my_work_metric_task_mentions'", $service);
        $this->assertStringNotContainsString("my_work_metric_job_mentions", $service);
    }
}
