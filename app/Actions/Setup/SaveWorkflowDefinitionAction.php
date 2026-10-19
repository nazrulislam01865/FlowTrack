<?php
namespace App\Actions\Setup;
use App\Models\WorkflowTemplate;
use App\Services\OrderWorkflowSetupService;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\DB;
class SaveWorkflowDefinitionAction
{
    public function __construct(private readonly WorkflowService $workflows, private readonly OrderWorkflowSetupService $orders) {}
    public function execute(array $data, ?int $workflowId = null, ?WorkflowTemplate $source = null): WorkflowTemplate
    {
        return DB::transaction(function () use ($data, $workflowId, $source) {
            $workflow = $this->workflows->saveWorkflow($data, $workflowId);
            if (! $workflowId) {
                if ((string) ($data['applies_to'] ?? '') === 'orders') $this->orders->initializeWorkflowTemplate($workflow, $source);
                elseif ($source) $this->workflows->copyPhases($source, $workflow);
            }
            return $workflow;
        });
    }
}
