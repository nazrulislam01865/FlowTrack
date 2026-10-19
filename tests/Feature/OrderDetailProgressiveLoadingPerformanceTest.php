<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderDetailProgressiveLoadingPerformanceTest extends TestCase
{
    public function test_order_detail_heavy_sections_are_serialized_in_one_queue(): void
    {
        $overview = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));
        $products = file_get_contents(resource_path('views/livewire/jobs/order-products-section.blade.php'));
        $workflow = file_get_contents(resource_path('views/livewire/jobs/order-workflow-section.blade.php'));
        $attachments = file_get_contents(resource_path('views/livewire/jobs/order-attachments-section.blade.php'));
        $activity = file_get_contents(resource_path('views/livewire/jobs/order-activity-section.blade.php'));
        $loader = file_get_contents(resource_path('views/components/ui/progressive-section-loader.blade.php'));

        foreach ([
            '<livewire:jobs.order-products-section',
            '<livewire:jobs.order-workflow-section',
            '<livewire:jobs.order-attachments-section',
            '<livewire:jobs.order-activity-section',
        ] as $component) {
            $this->assertStringContainsString($component, $overview);
        }

        $this->assertStringNotContainsString('method="loadDetailSection"', $overview);

        foreach ([
            [$products, '10'],
            [$workflow, '20'],
            [$attachments, '30'],
            [$activity, '40'],
        ] as [$view, $priority]) {
            $this->assertStringContainsString('queue-group="order-detail-{{ $orderId }}"', $view);
            $this->assertStringContainsString(':queue-priority="'.$priority.'"', $view);
        }

        $this->assertStringContainsString('FlowTrackProgressiveSectionQueue', $loader);
        $this->assertStringContainsString('state.running = true;', $loader);
        $this->assertStringContainsString('schedule(group, next.settleDelay);', $loader);
        $this->assertStringContainsString('$el.isConnected && eligible && !requested', $loader);
    }

    public function test_already_synchronized_orders_do_not_run_the_full_workflow_repair_transaction(): void
    {
        $binding = file_get_contents(app_path('Services/OrderWorkflowBindingService.php'));
        $setup = file_get_contents(app_path('Services/OrderWorkflowSetupService.php'));

        $this->assertStringContainsString('runtimeMatchesPublishedDefinition(', $binding);
        $this->assertStringContainsString('if ($this->runtimeMatchesPublishedDefinition($job, $workflow, $phases))', $binding);
        $this->assertStringContainsString('return false;', $binding);
        $this->assertStringContainsString("->whereNull('workflow_id')", $setup);
        $this->assertStringContainsString("->orWhere('workflow_id', '!=', \$workflow->id)", $setup);
    }
    public function test_order_open_auto_advance_uses_current_phase_read_model_instead_of_full_detail_graph(): void
    {
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));

        $this->assertStringContainsString('private function findVisibleForAutoAdvance(User $actor, int $id): FlowJob', $service);
        $this->assertStringContainsString('$this->loadVisibleOverviewSummary($job, $actor);', $service);
        $this->assertStringContainsString("\$currentPhase->load(['taskPack.items.documentCategory']);", $service);
        $this->assertStringContainsString("\$job->tasks->loadMissing(['documents', 'links']);", $service);
        $this->assertStringContainsString('$job = $this->findVisibleForAutoAdvance($actor, $job->id);', $service);
        $legacyFullGraphCall = <<<'PHP'
public function maybeAutoAdvance(FlowJob $job, User $actor): void
    {
        $job = $this->findVisible($actor, $job->id);
PHP;

        $this->assertStringNotContainsString($legacyFullGraphCall, $service);
    }

}
