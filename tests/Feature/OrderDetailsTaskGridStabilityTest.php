<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderDetailsTaskGridStabilityTest extends TestCase
{
    public function test_order_detail_task_grid_stability_guard_is_loaded_last(): void
    {
        $entry = (string) file_get_contents(resource_path('css/modules/orders/detail-prototype.css'));
        $guardImport = "@import './detail/permanent-task-grid.css';";

        $this->assertStringContainsString($guardImport, $entry);
        $this->assertSame(
            strrpos($entry, $guardImport),
            strrpos($entry, '@import'),
            'The task-grid stability guard must remain the final Order Details CSS import.'
        );
    }

    public function test_order_detail_task_grid_uses_deterministic_coordinates_at_responsive_widths(): void
    {
        $guard = (string) file_get_contents(resource_path('css/modules/orders/detail/permanent-task-grid.css'));

        $this->assertStringContainsString('@container ft-order-workflow-detail (max-width: 1320px)', $guard);
        $this->assertStringContainsString('@container ft-order-workflow-detail (max-width: 960px)', $guard);
        $this->assertStringContainsString('@container ft-order-workflow-detail (max-width: 560px)', $guard);
        $this->assertStringContainsString('grid-template-areas: none !important;', $guard);
        $this->assertStringContainsString('grid-column: 2 / 4 !important;', $guard);
        $this->assertStringContainsString('grid-column: 1 / 3 !important;', $guard);
        $this->assertStringContainsString('grid-row: 5 !important;', $guard);
    }

    public function test_legacy_responsive_layers_do_not_cancel_named_grid_areas_with_auto_columns(): void
    {
        $detail = (string) file_get_contents(resource_path('css/modules/orders/detail/detail-02.css'));
        $responsive = (string) file_get_contents(resource_path('css/modules/orders/detail/responsive-task-cards.css'));

        foreach (['copy', 'assignee', 'due', 'state', 'actions'] as $area) {
            $badPattern = "grid-area: {$area} !important;\n        grid-column: auto !important;";
            $this->assertStringNotContainsString($badPattern, $detail);
            $this->assertStringNotContainsString($badPattern, $responsive);
        }
    }
}
