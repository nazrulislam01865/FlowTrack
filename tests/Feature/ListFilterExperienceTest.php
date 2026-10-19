<?php

namespace Tests\Feature;

use Tests\TestCase;

class ListFilterExperienceTest extends TestCase
{
    public function test_shared_large_filter_runtime_limits_remote_results_and_cancels_stale_requests(): void
    {
        $service = file_get_contents(app_path('Services/FilterOptionService.php'));
        $runtime = file_get_contents(resource_path('js/components/list-filters.js'));

        $this->assertStringContainsString('public const MAX_PER_PAGE = 20;', $service);
        $this->assertStringContainsString('public const MIN_SEARCH_LENGTH = 2;', $service);
        $this->assertStringContainsString('->offset($offset)', $service);
        $this->assertStringContainsString('new AbortController()', $runtime);
        $this->assertStringContainsString('this.controller?.abort()', $runtime);
        $this->assertStringContainsString("x-on:input.debounce.300ms", file_get_contents(resource_path('views/components/ui/search-select.blade.php')));
    }

    public function test_dropdown_repositioning_measures_unconstrained_height_so_scroll_cannot_permanently_shrink_it(): void
    {
        $runtime = file_get_contents(resource_path('js/components/list-filters.js'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('measureNaturalMenuHeight', $runtime);
        $this->assertStringContainsString("menu.style.setProperty('max-height', 'none', 'important')", $runtime);
        $this->assertStringContainsString('const measuredHeight = menu.scrollHeight;', $runtime);
        $this->assertStringContainsString('const naturalHeight = measureNaturalMenuHeight(menu, heightCap);', $runtime);
        $this->assertStringContainsString('resources/js/app.js', $layout);
        $this->assertStringNotContainsString('/js/flowtrack-list-filters.js', $layout);
    }

    public function test_remote_selector_never_pairs_a_new_value_with_the_previous_options_label(): void
    {
        $runtime = file_get_contents(resource_path('js/components/list-filters.js'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('knownLabels: initialLabels', $runtime);
        $this->assertStringContainsString('const knownLabel = this.knownLabels.get(next);', $runtime);
        $this->assertStringContainsString('syncSelection(selection, params = {}, serverItems = [])', $runtime);
        $this->assertStringContainsString('disabled: config.disabled === true', $runtime);
        $this->assertStringContainsString('syncDisabled(disabled = false)', $runtime);
        $this->assertStringContainsString('if (this.disabled) return;', $runtime);
        $this->assertStringContainsString('syncDisabled(@js((bool) $disabled))', file_get_contents(resource_path('views/components/ui/search-select.blade.php')));
        $this->assertStringContainsString('if (this.pendingAt)', $runtime);
        $this->assertStringContainsString('if ((Date.now() - this.pendingAt) < 15000) return;', $runtime);
        $this->assertStringNotContainsString('currentLabel || suppliedLabel', $runtime);
        $this->assertStringContainsString('const resolved = item?.label || knownLabel || suppliedLabel || next;', $runtime);
        $this->assertStringContainsString('this.knownLabels.set(next, nextLabel);', $runtime);
        $this->assertStringContainsString('resources/js/app.js', $layout);
        $this->assertStringNotContainsString('/js/flowtrack-list-filters.js', $layout);
    }

    public function test_list_pages_use_the_phase_four_shared_filter_primitives(): void
    {
        $orders = file_get_contents(resource_path('views/components/jobs/table.blade.php'));
        $clients = \Tests\Support\AdministrationPhase7Source::clientsView();
        $documents = file_get_contents(resource_path('views/livewire/documents/index.blade.php'));
        $inquiries = $this->inquiryViewSource();

        $this->assertStringContainsString('<x-ui.filter-bar', $orders);
        $this->assertStringContainsString('<x-ui.search-select', $orders);
        $this->assertStringContainsString('<x-ui.date-range', $orders);
        $this->assertStringContainsString('<x-ui.filter-reset', $orders);

        $this->assertStringContainsString('<x-ui.search-input', $clients);
        $this->assertStringContainsString('<x-ui.search-select', $clients);

        $this->assertStringContainsString('<x-ui.filter-bar', $documents);
        $this->assertStringContainsString('type="clients"', $documents);
        $this->assertStringContainsString('type="users"', $documents);
        $this->assertStringContainsString('type="jobs"', $documents);

        $this->assertStringContainsString('<x-ui.search-input', $inquiries);
        $this->assertStringContainsString('<x-ui.filter-bar', $inquiries);
        $this->assertStringContainsString('property="pendingListStatus"', $inquiries);
    }

    public function test_heavy_filter_lookups_are_not_loaded_as_full_lists_on_board_my_work_or_documents(): void
    {
        $board = file_get_contents(app_path('Livewire/Board/Index.php'));
        $myWork = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $documents = file_get_contents(app_path('Livewire/Documents/Index.php'));

        $this->assertStringNotContainsString('$service->lookups($user', $board);
        $this->assertStringNotContainsString("->limit(250)\n            ->get(['id', 'job_number', 'title', 'client_id'])", $myWork);
        $this->assertStringContainsString('$this->showUpload', $documents);
        $this->assertStringContainsString("->options(\$user, 'jobs', 'documents'", $documents);
    }

    public function test_task_assignee_picker_uses_compact_initial_results_and_department_metadata(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/FilterOptionController.php'));
        $service = file_get_contents(app_path('Services/FilterOptionService.php'));

        $this->assertStringContainsString('FilterOptionService::COMPACT_PER_PAGE', $controller);
        $this->assertStringContainsString("if (\$context === 'task-assignee')", $service);
        $this->assertStringContainsString("with('department:id,name')", $service);
        $this->assertStringContainsString("'meta' => (string) (\$row->department?->name ?: '')", $service);
    }

    public function test_create_job_product_filter_supports_legacy_products_missing_parent_links(): void
    {
        $service = file_get_contents(app_path('Services/FilterOptionService.php'));
        $master = file_get_contents(app_path('Services/MasterDataService.php'));

        $this->assertStringContainsString("whereNull('parent_id')", $service);
        $this->assertStringContainsString("where('description', \$category)", $service);
        $this->assertStringContainsString("->orWhereLike('description', \$category.' ·%')", $service);
        $this->assertStringContainsString('Older demo/legacy Product rows did not always have parent_id set.', $master);
    }

    public function test_my_work_uses_the_grouped_personal_work_prototype_instead_of_the_shared_filter_grid(): void
    {
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));

        $this->assertStringContainsString('metrics ft-summary-card-grid', $view);
        $this->assertStringContainsString('wire:model.live.debounce.650ms="search"', $view);
        $this->assertStringContainsString('placeholder="Search tasks, Orders, clients or flags"', $view);
        $this->assertStringContainsString('Updating tasks…', $view);
        $this->assertStringContainsString('Mentions (', $view);
        $this->assertStringContainsString('use WithPagination;', $component);
        $this->assertStringNotContainsString('ft-list-filter-shell', $view);
    }
}
