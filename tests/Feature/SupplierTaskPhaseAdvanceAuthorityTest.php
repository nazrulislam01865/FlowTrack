<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Guard against a supplier delegate's task visibility controlling Order progression. */
class SupplierTaskPhaseAdvanceAuthorityTest extends TestCase
{
    public function test_automatic_progression_checks_all_current_phase_tasks_and_document_evidence(): void
    {
        $source = file_get_contents(app_path('Services/LegacyJobService.php'));
        $this->assertStringContainsString('private function loadCanonicalCurrentPhaseTasks(FlowJob $job): void', $source);
        $this->assertStringContainsString("->where('flow_job_id', \$job->id)", $source);
        $this->assertStringContainsString("->where('workflow_phase_id', (int) \$job->workflow_phase_id)", $source);
        $this->assertStringContainsString("->with(['setupTemplate.documentCategory', 'template', 'documentCategory', 'documents', 'links'])", $source);
        $this->assertStringContainsString("\$currentPhase->load(['taskPack.items.documentCategory']);", $source);

        $check = substr($source,
            strpos($source, 'private function findVisibleForAutoAdvance('),
            strpos($source, 'public function maybeAutoAdvance(') - strpos($source, 'private function findVisibleForAutoAdvance('));
        $this->assertStringContainsString('$this->loadCanonicalCurrentPhaseTasks($job);', $check);
        $this->assertStringContainsString('$this->findVisibleBase($actor, $id);', $check);
    }

    public function test_automatic_completion_rechecks_real_blockers_under_transaction(): void
    {
        $source = file_get_contents(app_path('Services/LegacyJobService.php'));
        $method = substr($source,
            strpos($source, 'public function completePhase('),
            strpos($source, 'public function moveToPhase(') - strpos($source, 'public function completePhase('));

        $this->assertStringContainsString('return DB::transaction(function () use ($job, $actor, $automatic)', $method);
        $this->assertStringContainsString('$job = $this->findVisible($actor, $job->id);', $method);
        $this->assertStringContainsString('if ($automatic) {', $method);
        $this->assertStringContainsString('$this->loadCanonicalCurrentPhaseTasks($job);', $method);
        $this->assertStringContainsString('$blockers = JobDetailPresenter::blockers($job);', $method);
        $this->assertStringContainsString('if ($blockers->isNotEmpty())', $method);
    }

    public function test_supplier_actions_remain_authenticated_authorized_and_use_existing_status_hooks(): void
    {
        $bridge = file_get_contents(app_path('Http/Controllers/SupplierBridge/ReceiveSupplierTaskAction.php'));
        $tasks = file_get_contents(app_path('Services/TaskService.php'));

        $this->assertStringContainsString('hash_equals(hash_hmac(', $bridge);
        $this->assertStringContainsString('abort_unless($access->canEditTask($actor, $task), 403);', $bridge);
        $this->assertStringContainsString('app(OrderTaskSequenceService::class)->synchronizeCurrentPhase($job, $actor);', $tasks);
        $this->assertStringContainsString('app(JobService::class)->maybeAutoAdvance($job->refresh(), $actor);', $tasks);
    }
}
