<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderHealthPresentationRemovedTest extends TestCase
{
    public function test_order_health_is_not_rendered_or_exported(): void
    {
        $files = [
            resource_path('views/components/jobs/order-detail/header.blade.php'),
            resource_path('views/components/jobs/task-detail/sidebar.blade.php'),
            resource_path('views/components/jobs/table.blade.php'),
            resource_path('views/livewire/my-work/_order-groups-v5.blade.php'),
            resource_path('views/livewire/board/index.blade.php'),
            resource_path('views/livewire/clients/sections/list.blade.php'),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            $this->assertStringNotContainsString('Order health', $contents, $file);
            $this->assertStringNotContainsString('>Health<', $contents, $file);
        }

        $export = file_get_contents(app_path('Services/ListExportService.php'));
        $this->assertStringNotContainsString("'Health', 'Flag'", $export);
        $this->assertStringNotContainsString("'Health Override'", $export);
    }

    public function test_health_storage_is_kept_for_backward_compatibility(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_04_000100_create_flowtrack_core_tables.php'));
        $this->assertStringContainsString("\$table->string('health')->default('On Track')", $migration);
    }
}
