<?php
namespace App\Actions\Setup;
use App\Models\WorkflowPhase;
use App\Services\WorkflowService;
class DeleteWorkflowPhaseAction { public function __construct(private readonly WorkflowService $workflows) {} public function execute(WorkflowPhase $phase): void { $this->workflows->delete($phase); } }
