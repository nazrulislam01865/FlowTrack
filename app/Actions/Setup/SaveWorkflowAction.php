<?php
namespace App\Actions\Setup;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowService;
class SaveWorkflowAction { public function __construct(private readonly WorkflowService $workflows) {} public function execute(array $data, ?int $id = null): WorkflowTemplate { return $this->workflows->saveWorkflow($data, $id); } }
