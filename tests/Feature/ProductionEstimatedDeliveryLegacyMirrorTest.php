<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionEstimatedDeliveryLegacyMirrorTest extends TestCase
{
    public function test_runtime_repairs_missing_legacy_task_pack_mirror_before_creating_order_task(): void
    {
        $taskPackService = file_get_contents(app_path('Services/TaskPackService.php'));
        $jobService = file_get_contents(app_path('Services/LegacyJobService.php'));
        $migration = file_get_contents(database_path('migrations/2026_08_24_180100_repair_estimated_delivery_task_legacy_mirror.php'));

        $this->assertStringContainsString('ensureLegacyMirrorsForPack', $taskPackService);
        $this->assertStringContainsString('TaskPackTask::query()', $taskPackService);
        $this->assertStringContainsString('->normalize((int) $pack->id)', $taskPackService);

        $this->assertStringContainsString('ensureLegacyMirrorsForPack($phase->taskPack)', $jobService);
        $this->assertStringContainsString('tasks.task_pack_task_id still has a foreign key', $jobService);

        $this->assertStringContainsString("private const KEY = 'PROD_SET_ESTIMATED_DELIVERY'", $migration);
        $this->assertStringContainsString("Schema::hasTable('task_pack_tasks')", $migration);
        $this->assertStringContainsString("'id' => $item->id", $migration);
    }
}
