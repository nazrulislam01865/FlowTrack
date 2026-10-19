<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\FilterOptionService;
use App\Services\OrderWorkflowSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CreateOrderWorkflowSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_specific_order_workflow_is_the_default_for_create_order_and_can_be_changed(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $nep = Client::create(['name' => 'NEP', 'code' => 'NEP', 'is_active' => true]);

        $generic = $this->readyOrderWorkflow('Standard Order Workflow', 'ORDER', 'all', true);
        $specific = $this->readyOrderWorkflow('NEP Order Workflow', 'NEP-ORDER', 'specific', false, [$nep->id]);
        $specificPhase = $specific->phases()->where('is_active', true)->orderBy('sequence')->firstOrFail();
        $genericPhase = $generic->phases()->where('is_active', true)->orderBy('sequence')->firstOrFail();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Jobs\Index::class)
            ->call('openCreate')
            ->assertSet('clientId', $nep->id)
            ->call('loadCreateSection', 'catalog')
            ->call('loadCreateSection', 'assignment')
            ->call('loadCreateSection', 'workflow')
            ->assertSet('workflowId', $specific->id)
            ->assertSet('workflowPhaseId', $specificPhase->id)
            ->assertSee('NEP Order Workflow')
            ->call('setCreateSelector', 'workflowId', (string) $generic->id)
            ->assertSet('workflowId', $generic->id)
            ->assertSet('workflowPhaseId', $genericPhase->id);
    }

    public function test_changing_client_replaces_the_previous_workflow_with_the_new_clients_configured_default(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $nep = Client::create(['name' => 'NEP', 'code' => 'NEP', 'is_active' => true]);
        $other = Client::create(['name' => 'Other Client', 'code' => 'OTHER', 'is_active' => true]);

        $generic = $this->readyOrderWorkflow('Standard Order Workflow', 'ORDER', 'all', true);
        $nepWorkflow = $this->readyOrderWorkflow('NEP Order Workflow', 'NEP-ORDER', 'specific', false, [$nep->id]);
        $otherWorkflow = $this->readyOrderWorkflow('Other Client Order Workflow', 'OTHER-ORDER', 'specific', false, [$other->id]);

        $genericPhase = $generic->phases()->where('is_active', true)->orderBy('sequence')->firstOrFail();
        $nepPhase = $nepWorkflow->phases()->where('is_active', true)->orderBy('sequence')->firstOrFail();
        $otherPhase = $otherWorkflow->phases()->where('is_active', true)->orderBy('sequence')->firstOrFail();

        Livewire::actingAs($user)
            ->test(\App\Livewire\Jobs\Index::class)
            ->call('openCreate')
            ->call('loadCreateSection', 'workflow')
            ->call('setCreateSelector', 'clientId', (string) $nep->id)
            ->assertSet('workflowId', $nepWorkflow->id)
            ->assertSet('workflowPhaseId', $nepPhase->id)
            ->call('setCreateSelector', 'workflowId', (string) $generic->id)
            ->assertSet('workflowId', $generic->id)
            ->assertSet('workflowPhaseId', $genericPhase->id)
            ->call('setCreateSelector', 'clientId', (string) $other->id)
            ->assertSet('workflowId', $otherWorkflow->id)
            ->assertSet('workflowPhaseId', $otherPhase->id)
            ->assertSee('Other Client Order Workflow')
            ->call('setCreateSelector', 'clientId', (string) $nep->id)
            ->assertSet('workflowId', $nepWorkflow->id)
            ->assertSet('workflowPhaseId', $nepPhase->id)
            ->assertSee('NEP Order Workflow');
    }

    public function test_create_order_workflow_options_include_only_order_workflows_available_to_that_client(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $nep = Client::create(['name' => 'NEP', 'code' => 'NEP', 'is_active' => true]);
        $other = Client::create(['name' => 'Other Client', 'code' => 'OTHER', 'is_active' => true]);

        $this->readyOrderWorkflow('Standard Order Workflow', 'ORDER', 'all', true);
        $nepOrder = $this->readyOrderWorkflow('Renamed NEP Workflow', 'NEP-ORDER', 'specific', false, [$nep->id]);
        $otherOrder = $this->readyOrderWorkflow('Other Order Workflow', 'OTHER-ORDER', 'specific', false, [$other->id]);
        $genericInquiry = $this->inquiryWorkflow('Generic Inquiry Workflow', 'GEN-INQ', 'all');
        $nepInquiry = $this->inquiryWorkflow('NEP Inquiry Workflow', 'NEP-INQ', 'specific', [$nep->id]);

        $items = app(FilterOptionService::class)
            ->options($user, 'workflows', 'create-job', '', null, 20, ['client_id' => $nep->id]);

        $labels = $items->pluck('label')->all();
        $this->assertContains('Standard Order Workflow', $labels);
        $this->assertContains($nepOrder->name, $labels);
        $this->assertNotContains($otherOrder->name, $labels);
        $this->assertNotContains($genericInquiry->name, $labels);
        $this->assertNotContains($nepInquiry->name, $labels);
        $this->assertSame($nepOrder->name, $labels[0]);
    }

    private function readyOrderWorkflow(
        string $name,
        string $code,
        string $availability,
        bool $default = false,
        array $clientIds = [],
    ): WorkflowTemplate {
        $workflow = WorkflowTemplate::create([
            'workspace_id' => 1,
            'name' => $name,
            'code' => $code,
            'applies_to' => 'orders',
            'client_availability' => $availability,
            'is_active' => true,
            'is_default' => $default,
            'version' => 1,
        ]);

        if ($clientIds !== []) $workflow->clients()->sync($clientIds);
        app(OrderWorkflowSetupService::class)->initializeWorkflowTemplate($workflow);

        return $workflow->refresh();
    }

    private function inquiryWorkflow(string $name, string $code, string $availability, array $clientIds = []): WorkflowTemplate
    {
        $workflow = WorkflowTemplate::create([
            'workspace_id' => 1,
            'name' => $name,
            'code' => $code,
            'applies_to' => 'inquiries',
            'client_availability' => $availability,
            'is_active' => true,
            'is_default' => false,
            'version' => 1,
        ]);
        if ($clientIds !== []) $workflow->clients()->sync($clientIds);

        return $workflow;
    }
}
