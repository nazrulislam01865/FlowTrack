<?php
namespace App\Actions\Setup;
use App\Services\WorkflowService;
class DeleteWorkflowAction { public function __construct(private readonly WorkflowService $workflows) {} public function execute(int $id): array { return $this->workflows->deleteWorkflow($id); } }
