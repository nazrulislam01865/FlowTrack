<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderDetailsFullIsolationPerformanceTest extends TestCase
{
    public function test_initial_order_details_uses_lightweight_shell_data(): void
    {
        $builder = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $detail = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderDetail.php'));

        $jobPageStart = strpos($builder, 'private function jobPageData(User $user): array');
        $jobPageEnd = strpos($builder, 'private function ', $jobPageStart + 20);
        $jobPage = substr($builder, $jobPageStart, $jobPageEnd - $jobPageStart);

        $this->assertStringContainsString('OrderWorkflowSummaryService::class)->forViewer($selected, $user)', $jobPage);
        $this->assertStringNotContainsString('$orderQuery->loadOverviewShell($selected, $user);', $jobPage);
        $this->assertStringContainsString('->buildSummary($selected, $user, $shipmentUrgencyOptions, $shipmentMethodOptions)', $builder);
        $this->assertStringContainsString('->summaryContext($selected, $user)', $builder);

        $prepareStart = strpos($detail, 'private function prepareSelectedJob(int $id): void');
        $prepareEnd = strpos($detail, 'private function setDefaultDocumentTask', $prepareStart);
        $prepare = substr($detail, $prepareStart, $prepareEnd - $prepareStart);

        $this->assertStringContainsString('VisibleOrderQuery::class)->scoped(', $prepare);
        $this->assertStringNotContainsString('syncSingleActiveOrder', $prepare);
        $this->assertStringNotContainsString('OrderArtworkEvidenceService', $prepare);
        $this->assertStringNotContainsString('AutoAdvanceOrder', $prepare);
    }

    public function test_workflow_attachments_and_activity_have_independent_livewire_components(): void
    {
        $overview = file_get_contents(resource_path('views/components/jobs/detail-overview.blade.php'));
        $workflow = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $attachments = file_get_contents(app_path('Livewire/Jobs/OrderAttachmentsSection.php'));
        $activity = file_get_contents(app_path('Livewire/Jobs/OrderActivitySection.php'));

        $this->assertStringContainsString('<livewire:jobs.order-workflow-section', $overview);
        $this->assertStringContainsString('<livewire:jobs.order-attachments-section', $overview);
        $this->assertStringContainsString('<livewire:jobs.order-activity-section', $overview);

        $this->assertStringContainsString('final class OrderWorkflowSection extends Component', $workflow);
        $this->assertStringContainsString('loadOverviewWorkflow($job, $user)', $workflow);
        $this->assertStringContainsString('syncSingleActiveOrder($this->orderId)', $workflow);
        $this->assertStringContainsString('AutoAdvanceOrder::class)->handle', $workflow);

        $this->assertStringContainsString('final class OrderAttachmentsSection extends Component', $attachments);
        $this->assertStringContainsString('->base(auth()->user(), $this->orderId)', $attachments);
        $this->assertStringNotContainsString('->detail(', $attachments);

        $this->assertStringContainsString('final class OrderActivitySection extends Component', $activity);
        $this->assertStringContainsString('loadOverviewActivity($job, $this->jobActivityTab, $this->jobActivityPage, 10)', $activity);
    }

    public function test_progressive_order_is_products_workflow_attachments_activity(): void
    {
        $views = [
            'products' => [file_get_contents(resource_path('views/livewire/jobs/order-products-section.blade.php')), '10'],
            'workflow' => [file_get_contents(resource_path('views/livewire/jobs/order-workflow-section.blade.php')), '20'],
            'attachments' => [file_get_contents(resource_path('views/livewire/jobs/order-attachments-section.blade.php')), '30'],
            'activity' => [file_get_contents(resource_path('views/livewire/jobs/order-activity-section.blade.php')), '40'],
        ];

        foreach ($views as [$view, $priority]) {
            $this->assertStringContainsString('queue-group="order-detail-{{ $orderId }}"', $view);
            $this->assertStringContainsString(':queue-priority="'.$priority.'"', $view);
        }
    }
}
