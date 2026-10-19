<?php

namespace Tests\Feature;

use App\Services\FilterOptionService;
use Tests\TestCase;

class SharedFilterArchitectureTest extends TestCase
{
    public function test_remote_selector_contract_is_bounded_and_paged(): void
    {
        $service = file_get_contents(app_path('Services/FilterOptionService.php'));
        $page = file_get_contents(app_path('Support/Filters/FilterOptionPage.php'));

        $this->assertSame(20, FilterOptionService::MAX_PER_PAGE);
        $this->assertSame(2, FilterOptionService::MIN_SEARCH_LENGTH);
        $this->assertStringContainsString('public function searchPage(', $service);
        $this->assertStringContainsString("'selected_items' =>", $page);
        $this->assertStringContainsString("'has_more' =>", $page);
        $this->assertStringContainsString("'next_page' =>", $page);
    }

    public function test_incomplete_remote_search_does_not_fall_back_to_unrelated_rows(): void
    {
        $service = file_get_contents(app_path('Services/FilterOptionService.php'));

        $this->assertStringContainsString("if (\$search !== '' && mb_strlen(\$search) < self::MIN_SEARCH_LENGTH)", $service);
        $this->assertStringContainsString('items: collect()', $service);
    }

    public function test_phase_four_official_components_and_runtime_exist(): void
    {
        foreach ([
            'resources/views/components/ui/search-select.blade.php',
            'resources/views/components/ui/multi-select.blade.php',
            'resources/views/components/ui/search-input.blade.php',
            'resources/views/components/ui/filter-bar.blade.php',
            'resources/views/components/ui/filter-reset.blade.php',
            'resources/views/components/ui/date-range.blade.php',
            'resources/css/components/search-select.css',
            'resources/css/components/multi-select.css',
            'resources/css/components/filters.css',
            'resources/css/components/date-range.css',
        ] as $relative) {
            $this->assertFileExists(base_path($relative), $relative);
        }

        $runtime = file_get_contents(resource_path('js/components/list-filters.js'));
        $bridge = file_get_contents(resource_path('js/core/browser-api.js'));
        $this->assertStringContainsString('export const createSearchSelect', $runtime);
        $this->assertStringContainsString('export const createMultiSelect', $runtime);
        $this->assertStringContainsString('searchSelect: createSearchSelect', $bridge);
        $this->assertStringContainsString('multiSelect: createMultiSelect', $bridge);
        $this->assertStringContainsString('new AbortController()', $runtime);
    }

    public function test_workflow_and_product_specific_clients_use_shared_remote_multi_select(): void
    {
        $workflow = file_get_contents(resource_path('views/livewire/workflow-setup/form.blade.php'));
        $product = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));

        foreach ([$workflow, $product] as $source) {
            $this->assertStringContainsString('<x-ui.multi-select', $source);
            $this->assertStringContainsString('type="clients"', $source);
        }
    }

    public function test_department_selectors_share_the_same_search_select_contract(): void
    {
        foreach ([
            resource_path('views/livewire/user-editor/index.blade.php'),
            resource_path('views/livewire/administration/index.blade.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringContainsString('<x-ui.search-select', $source);
            $this->assertStringContainsString('type="departments"', $source);
            $this->assertStringContainsString('property="departmentId"', $source);
        }
    }
}
