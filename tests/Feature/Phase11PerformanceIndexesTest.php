<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase11PerformanceIndexesTest extends TestCase
{
    public function test_phase11_adds_indexes_for_real_operational_query_shapes(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_22_180000_add_phase11_performance_indexes.php'));
        foreach ([
            'ft_jobs_owner_open_due_idx', 'ft_jobs_phase_open_idx', 'ft_jobs_attention_updated_idx',
            'ft_tasks_job_open_due_idx', 'ft_tasks_phase_open_due_idx',
            'ft_inquiries_workspace_open_updated_idx', 'ft_inquiries_owner_open_idx',
            'ft_inquiries_client_updated_idx', 'ft_inquiry_tasks_parent_open_seq_idx',
            'ft_inquiry_tasks_assignee_open_due_idx', 'ft_inquiry_documents_parent_updated_idx',
            'ft_clients_active_archived_name_idx',
        ] as $index) {
            $this->assertStringContainsString($index, $migration);
        }
    }

    public function test_local_and_test_environments_detect_lazy_loading_without_changing_production_behavior(): void
    {
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $this->assertStringContainsString("environment('local', 'testing')", $provider);
        $this->assertStringContainsString('Model::preventLazyLoading()', $provider);
        $this->assertStringContainsString('handleLazyLoadingViolationUsing', $provider);
    }
}
