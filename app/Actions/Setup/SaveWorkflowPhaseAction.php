<?php
namespace App\Actions\Setup;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowService;
class SaveWorkflowPhaseAction { public function __construct(private readonly WorkflowService $workflows) {} public function execute(WorkflowTemplate $workflow, array $data, ?WorkflowPhase $phase = null, bool $authorize = true): WorkflowPhase { return $this->workflows->savePhase($workflow, $data, $phase, $authorize); } }
