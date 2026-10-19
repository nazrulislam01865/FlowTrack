<?php
namespace App\Actions\Setup;
use App\Services\OrderWorkflowSetupService;
class SaveOrderWorkflowAction { public function __construct(private readonly OrderWorkflowSetupService $workflow) {} public function execute(?int $workflowId, array $stages): array { return $this->workflow->save($workflowId, $stages); } }
