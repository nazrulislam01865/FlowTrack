<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkflowPhaseAutomaticControlsTest extends TestCase
{
    public function test_phase_form_keeps_only_phase_active_as_a_manual_control(): void
    {
        $view = file_get_contents(resource_path('views/livewire/workflow-setup/index.blade.php'));

        $this->assertStringNotContainsString('Allow users to create a Job starting from this phase', $view);
        $this->assertStringNotContainsString('Allow this phase to be skipped during normal progression', $view);
        $this->assertStringNotContainsString('Automatically move the Job when all task, document and blocker gates are ready', $view);
        $this->assertStringNotContainsString('<label>Required document</label>', $view);
        $this->assertStringContainsString('wire:model="phaseActive"', $view);
    }

    public function test_workflow_service_enforces_automatic_phase_controls(): void
    {
        $service = file_get_contents(app_path('Services/WorkflowService.php'));

        $this->assertStringContainsString("'allow_job_start' => true", $service);
        $this->assertStringContainsString("'is_skippable' => true", $service);
        $this->assertStringContainsString("'auto_advance_on_ready' => true", $service);
        $this->assertStringContainsString("\$payload['can_skip'] = true", $service);
    }
}
