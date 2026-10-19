<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderListBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_list_exposes_bulk_selection_and_confirmed_delete_controls(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/table.blade.php'));
        $bulkBar = file_get_contents(resource_path('views/components/jobs/bulk-delete-bar.blade.php'));
        $bulkModal = file_get_contents(resource_path('views/components/jobs/bulk-delete-confirmation.blade.php'));
        $component = file_get_contents(app_path('Livewire/Orders/Index.php'));

        $this->assertStringContainsString('aria-label="Select all orders on this page"', $view);
        $this->assertStringContainsString('wire:change="toggleOrderSelection({{ $job->id }})"', $view);
        $this->assertStringContainsString('<x-jobs.bulk-delete-bar :count="$selectedOrderCount" />', $view);
        $this->assertStringContainsString('wire:click="openBulkDeleteConfirmation"', $bulkBar);
        $this->assertStringNotContainsString('wire:confirm=', $bulkBar);
        $this->assertStringContainsString('Delete selected', $bulkBar);
        $this->assertStringContainsString('role="dialog"', $bulkModal);
        $this->assertStringContainsString('wire:click="closeBulkDeleteConfirmation"', $bulkModal);
        $this->assertStringContainsString('wire:click="bulkDeleteOrders"', $bulkModal);
        $this->assertStringContainsString('This action cannot be undone.', $bulkModal);
        $this->assertStringContainsString('public array $selectedOrderIds = [];', $component);
        $this->assertStringContainsString('public bool $showBulkDeleteConfirm = false;', $component);
        $this->assertStringContainsString('public function openBulkDeleteConfirmation(): void', $component);
        $this->assertStringContainsString('public function closeBulkDeleteConfirmation(): void', $component);
        $this->assertStringContainsString('public function bulkDeleteOrders(): void', $component);
    }

    public function test_super_admin_can_bulk_delete_multiple_visible_orders(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        [$client, $workflow, $phase] = $this->orderDependencies();

        $first = $this->createOrder($client, $workflow, $phase, $user, 'ORDER-BULK-DELETE-1');
        $second = $this->createOrder($client, $workflow, $phase, $user, 'ORDER-BULK-DELETE-2');

        $test = Livewire::actingAs($user)
            ->test(\App\Livewire\Orders\Index::class)
            ->set('selectedOrderIds', [$first->id, $second->id])
            ->call('openBulkDeleteConfirmation')
            ->assertSet('showBulkDeleteConfirm', true)
            ->call('bulkDeleteOrders')
            ->assertSet('showBulkDeleteConfirm', false)
            ->assertSet('selectedOrderIds', []);

        $source = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $this->assertStringContainsString("deleted successfully.'", $source);
        $this->assertSoftDeleted('flow_jobs', ['id' => $first->id]);
        $this->assertSoftDeleted('flow_jobs', ['id' => $second->id]);
    }

    private function orderDependencies(): array
    {
        $client = Client::create([
            'name' => 'Bulk Delete Client',
            'code' => 'BULK-DELETE',
            'is_active' => true,
        ]);
        $workflow = Workflow::create([
            'name' => 'Bulk Delete Workflow',
            'slug' => 'bulk-delete-workflow',
            'is_active' => true,
        ]);
        $phase = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Bulk Delete Phase',
            'short_name' => 'Bulk Delete',
            'allow_job_start' => true,
            'is_active' => true,
        ]);

        return [$client, $workflow, $phase];
    }

    private function createOrder(Client $client, Workflow $workflow, WorkflowPhase $phase, User $owner, string $number): FlowJob
    {
        return FlowJob::create([
            'job_number' => $number,
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $phase->id,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'title' => $number,
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
        ]);
    }
}
