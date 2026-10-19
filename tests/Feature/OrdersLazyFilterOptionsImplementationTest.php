<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrdersLazyFilterOptionsImplementationTest extends TestCase
{
    public function test_orders_render_does_not_preload_remote_filter_pages(): void
    {
        $component = file_get_contents(app_path('Livewire/Orders/Index.php'));

        $this->assertStringContainsString("'clientFilterOptions' => \$this->selectedFilterOptions", $component);
        $this->assertStringContainsString("'ownerFilterOptions' => \$this->selectedFilterOptions", $component);
        $this->assertStringContainsString("'stageAssigneeOptions' => \$this->selectedFilterOptions", $component);
        $this->assertStringContainsString("'stageClientFilterOptions' => \$this->selectedFilterOptions", $component);
        $this->assertStringContainsString("'supplierFilterOptions' => \$this->selectedFilterOptions", $component);

        $this->assertStringNotContainsString(
            "'clientFilterOptions' => \$options->options",
            $component,
        );
        $this->assertStringNotContainsString(
            "'ownerFilterOptions' => \$options->options",
            $component,
        );
        $this->assertStringNotContainsString(
            "'stageAssigneeOptions' => \$options->options",
            $component,
        );
        $this->assertStringNotContainsString(
            "'stageClientFilterOptions' => \$options->options",
            $component,
        );
        $this->assertStringNotContainsString(
            "'supplierFilterOptions' => \$options->options",
            $component,
        );
    }

    public function test_selected_filter_rows_are_resolved_without_loading_recent_option_windows(): void
    {
        $service = file_get_contents(app_path('Services/FilterOptionService.php'));
        $component = file_get_contents(app_path('Livewire/Orders/Index.php'));

        $this->assertStringContainsString('public function selectedOptions(', $service);
        $this->assertStringContainsString('->map(fn ($selectedId) => $this->resolveSelected(', $service);
        $this->assertStringContainsString('return $options->selectedOptions(', $component);
    }

    public function test_shared_remote_search_select_fetches_options_when_opened(): void
    {
        $runtime = file_get_contents(resource_path('js/components/list-filters.js'));
        $view = file_get_contents(resource_path('views/components/orders/list/filters.blade.php'));

        $this->assertStringContainsString('openMenu()', $runtime);
        $this->assertStringContainsString('this.searchOptions(true);', $runtime);
        $this->assertStringContainsString('type="clients"', $view);
        $this->assertStringContainsString('type="users"', $view);
        $this->assertStringContainsString('type="suppliers"', $view);
    }
}
