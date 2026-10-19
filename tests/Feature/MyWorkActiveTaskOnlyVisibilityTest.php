<?php

namespace Tests\Feature;

use Tests\TestCase;

class MyWorkActiveTaskOnlyVisibilityTest extends TestCase
{
    public function test_my_tasks_uses_one_structural_active_scope_for_all_roles(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));

        $this->assertStringContainsString('public function activeVisibleTaskQuery(User $user): Builder', $service);
        $this->assertStringContainsString("->leftJoin('workflow_phases as my_work_active_phase'", $service);
        $this->assertStringContainsString("->leftJoin('task_pack_items as my_work_active_template'", $service);
        $this->assertStringContainsString('applyStructuralActiveTaskConstraint($query)', $service);
        $this->assertStringContainsString('private function applyStructuralActiveTaskConstraint(Builder $query): void', $service);

        // Assignment is the visibility boundary for every role. Admin and
        // Super Admin do not bypass it on My Tasks.
        $activeScope = strstr($service, 'public function activeVisibleTaskQuery(User $user): Builder');
        $activeScope = strstr($activeScope, 'private function applyStructuralActiveTaskConstraint', true);
        $this->assertStringContainsString("->where('tasks.assignee_id', \$user->id)", $activeScope);
        $this->assertStringNotContainsString('isAdministrator($user)', $activeScope);
        $this->assertStringNotContainsString("->orWhereHas('job', fn (Builder \$job) => \$job->where('created_by', \$user->id))", $activeScope);
        $this->assertStringNotContainsString("->orWhereIn('tasks.id', $visibleByConfiguredAccess)", $activeScope);

        // Current phase and current saved Task Pack are both required. This is
        // what prevents stale cloud rows from obsolete packs appearing active.
        $this->assertStringContainsString("->whereColumn('flow_jobs.workflow_phase_id', 'tasks.workflow_phase_id')", $service);
        $this->assertStringContainsString("->orWhereNotNull('my_work_active_template.id')", $service);
        $this->assertStringContainsString("my_work_earlier_required_template.sort_order", $service);
        $this->assertStringContainsString("my_work_earlier_required.id', '<', 'tasks.id", $service);

        // Preserve the two conditional workflow branches used by Order Details.
        $this->assertStringContainsString('waiting for sample approval', $service);
        $this->assertStringContainsString('waiting for qc issue resolution', $service);
        $this->assertStringContainsString('ART_SAMPLE_APPROVAL', $service);
        $this->assertStringContainsString('QC_ISSUE', $service);

        // Every role also needs the table to advance to the next active assigned row.
        $this->assertStringContainsString("\$result['refresh'] = true;", $component);
    }

    public function test_stage_cards_and_counters_use_the_same_active_scope(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));

        $this->assertStringContainsString('$counts = $this->personalTaskQuery($user, [])', $service);
        $this->assertStringContainsString('->select([])', $service);
        $this->assertStringContainsString("my_work_card_task_phases.name", $service);
        $this->assertStringContainsString("->selectRaw('COUNT(tasks.id) AS aggregate')", $service);
        $this->assertStringContainsString("->groupBy('stage_key')", $service);
        $this->assertStringContainsString('$activeVisibleTaskIds = $this->activeVisibleTaskQuery($user)', $service);
        $this->assertStringContainsString("->whereIn('tasks.id', $activeVisibleTaskIds)", $service);
    }
}
