<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Task;
use App\Observers\WorkspaceDataObserver;
use App\Services\WorkspaceRefreshService;
use Mockery;
use Tests\TestCase;

class OrderPhase3RealtimeContractTest extends TestCase
{
    public function test_order_list_navigation_no_longer_prefetches_on_hover(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/table.blade.php'));

        $this->assertStringNotContainsString('wire:navigate.hover', $view);
        $this->assertStringContainsString("href=\"{{ route('jobs.index',['open'=>\$job->id]) }}\" wire:navigate", $view);
        $this->assertStringContainsString('wire:navigate', $view);
    }

    public function test_targeted_refresh_keeps_original_authorized_render_paths(): void
    {
        $components = [
            app_path('Livewire/Jobs/Index.php'),
            app_path('Livewire/Jobs/OrderProductsSection.php'),
            app_path('Livewire/Jobs/OrderWorkflowSection.php'),
            app_path('Livewire/Jobs/OrderAttachmentsSection.php'),
            app_path('Livewire/Jobs/OrderActivitySection.php'),
        ];

        foreach ($components as $file) {
            $source = file_get_contents($file);
            $this->assertStringContainsString("#[On('flowtrack-order-changed')]", $source);
            $this->assertStringContainsString('public function refreshTargetedOrder(int $orderId)', $source);
            $this->assertStringContainsString("#[On('flowtrack-notification')]", $source);
        }

        $this->assertStringContainsString("'order_targeted_events_enabled' => (bool) env(",
            file_get_contents(config_path('flowtrack.php')));
        $this->assertStringContainsString('data-flowtrack-order-targeted=',
            file_get_contents(resource_path('views/components/jobs/detail.blade.php')));
    }

    public function test_model_observer_scopes_known_orders_without_loading_relationships(): void
    {
        $refresh = Mockery::mock(WorkspaceRefreshService::class);
        $refresh->shouldReceive('touch')->once()->with('FlowJob:updated', 41);
        $refresh->shouldReceive('touch')->once()->with('Task:updated', 41);
        $refresh->shouldReceive('touch')->once()->with('Document:created', 41);
        $refresh->shouldReceive('touch')->once()->with('Activity:created', 41);
        $refresh->shouldReceive('touch')->once()->with('Activity:updated', null);
        $refresh->shouldReceive('touch')->once()->with('Client:updated', null);
        app()->instance(WorkspaceRefreshService::class, $refresh);

        $observer = new WorkspaceDataObserver();
        $job = new FlowJob();
        $job->id = 41;
        $task = new Task();
        $task->setAttribute('flow_job_id', 41);
        $document = new Document();
        $document->setAttribute('flow_job_id', 41);

        $observer->updated($job);
        $observer->updated($task);
        $observer->created($document);
        $orderActivity = new Activity();
        $orderActivity->subject_type = FlowJob::class;
        $orderActivity->subject_id = 41;
        $observer->created($orderActivity);
        $taskActivity = new Activity();
        $taskActivity->subject_type = Task::class;
        $taskActivity->subject_id = 23;
        $observer->updated($taskActivity);
        $observer->updated(new Client());
    }
}
