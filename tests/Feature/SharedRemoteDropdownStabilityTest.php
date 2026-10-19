<?php

namespace Tests\Feature;

use Tests\TestCase;

class SharedRemoteDropdownStabilityTest extends TestCase
{
    public function test_all_remote_search_dropdowns_reopen_with_a_compact_recent_page(): void
    {
        $runtime = file_get_contents(resource_path('js/components/list-filters.js'));

        $this->assertStringContainsString('const REMOTE_RECENT_PAGE_SIZE = 5;', $runtime);
        $this->assertStringContainsString('const REMOTE_SEARCH_PAGE_SIZE = 20;', $runtime);
        $this->assertStringContainsString('const REMOTE_MENU_HEIGHT_CAP = 280;', $runtime);
        $this->assertGreaterThanOrEqual(2, substr_count($runtime, 'restoreCompactRecentPage()'));
        $this->assertSame(2, substr_count($runtime, "url.searchParams.set('per_page', String(q ? REMOTE_SEARCH_PAGE_SIZE : this.recentPageSize));"));
        $this->assertStringNotContainsString('recentPerPage', $runtime);
    }

    public function test_shared_dropdown_height_is_bounded_in_the_component_layer(): void
    {
        $css = file_get_contents(resource_path('css/components/list-filters.css'));
        $legacySharedCss = file_get_contents(resource_path('css/modules/application/10-shared-filters-order-overview.css'));

        $this->assertStringContainsString('--ft-remote-dropdown-max-height: 280px;', $css);
        $this->assertStringContainsString('max-height: min(var(--ft-remote-dropdown-max-height)', $css);
        $this->assertStringContainsString('max-height: min(var(--ft-remote-dropdown-max-height, 280px)', $legacySharedCss);
    }

    public function test_opening_a_teleported_dropdown_cannot_scroll_or_render_before_positioning(): void
    {
        $runtime = file_get_contents(resource_path('js/components/list-filters.js'));
        $css = file_get_contents(resource_path('css/components/search-select.css'));

        $this->assertStringContainsString('const focusElementWithoutScroll', $runtime);
        $this->assertStringContainsString('element.focus({preventScroll: true});', $runtime);
        $this->assertStringContainsString('positionAndFocusDropdown(this);', $runtime);
        $this->assertStringContainsString("visibility:hidden!important;pointer-events:none!important", $runtime);
        $this->assertStringNotContainsString('this.$refs.search?.focus();', $runtime);
        $this->assertStringContainsString('.ft-search-select__avatar img', $css);
        $this->assertStringContainsString('object-fit: cover;', $css);
    }

    public function test_teleported_people_dropdown_rows_keep_avatar_and_two_line_copy_inside_each_option(): void
    {
        $component = file_get_contents(resource_path('views/components/ui/search-select.blade.php'));
        $css = file_get_contents(resource_path('css/components/list-filters.css'));

        $this->assertStringContainsString("ft-search-select__menu--people", $component);
        $this->assertStringContainsString('data-ft-search-select-context=', $component);
        $this->assertStringContainsString('.ft-search-select__menu--people .ft-search-select__option', $css);
        $this->assertStringContainsString('height: auto !important;', $css);
        $this->assertStringContainsString('min-height: 48px !important;', $css);
        $this->assertStringContainsString('.ft-search-select__menu--people .ft-search-select__user-copy', $css);
        $this->assertStringContainsString('flex-direction: column;', $css);
        $this->assertStringContainsString('line-height: 1.2 !important;', $css);
    }

    public function test_dashboard_order_and_inquiry_lists_use_the_stable_fixed_dropdown_path(): void
    {
        $dashboard = file_get_contents(resource_path('views/livewire/dashboard/index.blade.php'));
        $orders = file_get_contents(resource_path('views/components/orders/list/filters.blade.php'));
        $inquiries = file_get_contents(resource_path('views/livewire/inquiries/sections/list.blade.php'));

        $this->assertStringContainsString('wire:key="dashboard-client-filter-', $dashboard);
        $this->assertStringContainsString('wire:key="dashboard-team-filter-', $dashboard);
        $this->assertGreaterThanOrEqual(2, substr_count($dashboard, '<x-ui.search-select'));
        $this->assertGreaterThanOrEqual(2, substr_count($dashboard, ':fixed-menu="true"'));

        $this->assertStringContainsString('ft-order-v5-client-filter', $orders);
        $this->assertStringContainsString('ft-order-v5-owner-filter', $orders);
        $this->assertGreaterThanOrEqual(2, substr_count($orders, ':fixed-menu="true"'));

        $this->assertStringContainsString('ft-inquiry-status-filter', $inquiries);
        $this->assertStringContainsString('ft-inquiry-list-client-filter', $inquiries);
        $this->assertGreaterThanOrEqual(2, substr_count($inquiries, ':fixed-menu="true"'));
    }
}
