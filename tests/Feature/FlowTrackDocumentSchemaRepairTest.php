<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlowTrackDocumentSchemaRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_linkage_schema_is_complete_and_repair_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_08_02_000100_repair_flow_document_linkage_schema.php');

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('flow_documents', [
            'workflow_phase_id',
            'task_pack_item_id',
            'flow_task_id',
            'linked_by',
            'linked_at',
        ]));
        $this->assertTrue(Schema::hasIndex('flow_documents', 'flow_docs_job_phase_category_idx'));
        $this->assertTrue(Schema::hasIndex('flow_documents', 'flow_docs_job_task_idx'));
        $this->assertTrue(Schema::hasIndex('flow_documents', 'ft_documents_task_category_active_idx'));
    }
}
