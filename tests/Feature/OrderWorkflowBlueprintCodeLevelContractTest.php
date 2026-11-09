<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Regression contracts for the final blueprint reader/mutation boundaries. */
final class OrderWorkflowBlueprintCodeLevelContractTest extends TestCase
{
    public function test_order_attachment_history_is_bounded_and_explicitly_extended(): void
    {
        $component = file_get_contents(app_path('Livewire/Jobs/OrderAttachmentsSection.php'));
        $view = file_get_contents(resource_path('views/components/jobs/order-detail/attachments.blade.php'));

        $this->assertStringContainsString('private const DOCUMENTS_PER_PAGE = 20', $component);
        $this->assertStringContainsString("->whereNull('task_id')", $component);
        $this->assertStringContainsString('->limit($documentLimit)', $component);
        $this->assertStringContainsString("->orderByDesc('id')", $component);
        $this->assertStringContainsString('$job->setRelation(\'documents\', $documents)', $component);
        $this->assertStringContainsString('public function loadMoreOrderDocuments()', $component);
        $this->assertStringContainsString('wire:click="loadMoreOrderDocuments"', $view);
        $this->assertStringContainsString('$context[\'attachmentTotal\']', $view);
        $this->assertStringContainsString('$query->loadOverviewDocuments($job)', $component);
        $this->assertArrayHasKey('order_attachment_pagination_enabled', config('flowtrack'));
        $this->assertStringContainsString('->base($user, $this->orderId)', $component);
    }

    public function test_optimized_workflow_keeps_stage_only_evidence_and_authorized_legacy_fallback(): void
    {
        $section = file_get_contents(app_path('Livewire/Jobs/OrderWorkflowSection.php'));
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $reader = file_get_contents(app_path('Queries/Orders/OrderWorkflowDisplayQuery.php'));

        $this->assertStringContainsString('->preferDisplayPhase(', $section);
        $this->assertStringContainsString('modalTaskId', $reader);
        $this->assertStringContainsString('applyTaskScope($query, $viewer)', $reader);
        $this->assertStringContainsString("withExists(['documents', 'links'])", $reader);
        $this->assertStringContainsString('$fullDocuments ? null : $displayTaskIds', $service);
        $this->assertStringContainsString('links_exists', $service);
        $this->assertStringContainsString('$job->load([\'tasks\' => $legacyTaskLoader])', $service);
        $this->assertStringContainsString('if ($selectedTaskIds !== null', $service);
    }

    public function test_batch_parent_and_locked_action_read_coalescing_are_independently_reversible(): void
    {
        $legacy = file_get_contents(app_path('Services/LegacyJobService.php'));
        $actions = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $tasks = file_get_contents(app_path('Services/TaskService.php'));

        $this->assertArrayHasKey('workflow_parent_coalesce_enabled', config('flowtrack'));
        $this->assertArrayHasKey('workflow_action_locked_task_reuse_enabled', config('flowtrack'));
        $this->assertStringContainsString('if ($deferredParent)', $legacy);
        $this->assertStringContainsString('$deferForPhase', $legacy);
        $this->assertStringContainsString('bool $deferParent = false', $legacy);
        $this->assertStringContainsString('$reuseLockedTask', $actions);
        $this->assertStringContainsString('bool $lockedFreshTask = false', $tasks);
        $this->assertStringContainsString('lockForUpdate()', $actions);
        $this->assertStringContainsString('if (! $lockedFreshTask)', $tasks);
    }

    public function test_realtime_keeps_all_transaction_subjects_and_order_scoping(): void
    {
        $backend = file_get_contents(app_path('Services/WorkspaceRefreshService.php'));
        $frontend = file_get_contents(resource_path('js/features/order-realtime.js'));
        $this->assertStringContainsString('private array $pendingReasons', $backend);
        $this->assertStringContainsString('implode(\'|\', array_keys($this->pendingReasons))', $backend);
        $this->assertStringContainsString('order_targeted_events_enabled', $backend);
        $this->assertStringContainsString('workspaceSectionsForSubject', $frontend);
        $this->assertStringContainsString("String(reason ?? '').split('|')", $frontend);
        $this->assertStringContainsString('sameWorkspace(current, payload)', $frontend);
        $this->assertStringContainsString('incomingId !== current.orderId', $frontend);
    }
}
