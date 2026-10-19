<?php
namespace App\Actions\Setup;
use App\Services\WorkflowService;
class ToggleWorkflowAction { public function __construct(private readonly WorkflowService $workflows) {} public function execute(int $id): void { $this->workflows->toggleWorkflow($id); } }
