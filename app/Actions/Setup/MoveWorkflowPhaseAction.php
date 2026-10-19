<?php
namespace App\Actions\Setup;
use App\Models\WorkflowPhase;
use App\Services\WorkflowService;
class MoveWorkflowPhaseAction { public function __construct(private readonly WorkflowService $workflows) {} public function execute(WorkflowPhase $phase, int $direction): void { $this->workflows->move($phase, $direction); } }
