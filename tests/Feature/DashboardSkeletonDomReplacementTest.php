<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Livewire must replace a stable descendant, never try to morph the component root. */
final class DashboardSkeletonDomReplacementTest extends TestCase
{
    private function assertSwapBoundary(string $html, string $key): void
    {
        $tag = '<div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-'.$key.'-content">';
        $this->assertStringContainsString($tag, $html, $key);
        $this->assertSame(1, substr_count($html, $tag), $key);
        $this->assertSame(1, substr_count($html, 'wire:replace'), $key);
        // The wrapper must be inside the panel's root, not on the component root.
        $this->assertMatchesRegularExpression('/<(?:div|article|section)\s[^>]+>\s*'.preg_quote($tag, '/').'/', $html, $key);
    }

    public function test_all_nine_loaded_panels_own_a_stable_nested_swap_boundary(): void
    {
        $views = [
            'overview-panel' => 'overview',
            'priority-panel' => 'priority',
            'attention-panel' => 'attention',
            'tagged-comments' => 'tagged-comments',
            'team-panel' => 'team',
            'portfolio-panel' => 'portfolio',
            'catalogue-panel' => 'catalogue',
            'activity-panel' => 'activity',
        ];
        $path = resource_path('views/livewire/dashboard/');

        foreach ($views as $view => $key) {
            $html = file_get_contents($path.$view.'.blade.php');
            $this->assertSwapBoundary($html, $key);
            $this->assertStringContainsString('data-dashboard-panel="'.$key.'"', $html);
            $this->assertStringNotContainsString('data-dashboard-skeleton-for=', $html);
            $this->assertStringNotContainsString('wire:replace.self', $html);
        }
    }

    public function test_all_placeholders_match_the_loaded_panels_at_the_same_boundary(): void
    {
        $path = resource_path('views/livewire/dashboard/');
        $this->assertSwapBoundary(file_get_contents($path.'overview-placeholder.blade.php'), 'overview');
        $this->assertSwapBoundary(file_get_contents($path.'mentions-placeholder.blade.php'), 'tagged-comments');

        $html = file_get_contents($path.'panel-placeholder.blade.php');
        $this->assertStringContainsString('<div class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid ft-mgmt-dashboard-panel-swap" wire:replace wire:key="dashboard-distribution-content">', $html);
        $this->assertSwapBoundaryInGenericView($html, 'catalogue');
        $this->assertStringContainsString(
            '<div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-{{ str_replace(\'panel\', \'\', $kind) }}-content">',
            $html,
        );
        $this->assertSame(2, substr_count($html, 'class="ft-mgmt-dashboard-panel-swap"'));
        $this->assertSame(3, substr_count($html, 'wire:replace'));
        // Intersections remain on nested children so they resolve to the correct component.
        $this->assertSame(3, substr_count($html, 'wire:intersect.once="loadDashboardPanel"'));
    }

    private function assertSwapBoundaryInGenericView(string $html, string $key): void
    {
        $needle = '<div class="ft-mgmt-dashboard-panel-swap" style="display:contents" wire:replace wire:key="dashboard-'.$key.'-content">';
        $this->assertSame(1, substr_count($html, $needle), $key);
    }

    public function test_distribution_cards_are_direct_grid_children_in_loading_and_loaded_states(): void
    {
        $path = resource_path('views/livewire/dashboard/');
        $loaded = file_get_contents($path.'distribution-panel.blade.php');
        $placeholder = file_get_contents($path.'panel-placeholder.blade.php');
        $grid = '<div class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid ft-mgmt-dashboard-panel-swap" wire:replace wire:key="dashboard-distribution-content">';

        foreach ([$loaded, $placeholder] as $html) {
            $this->assertStringContainsString($grid, $html);
            $this->assertStringNotContainsString('style="display:contents" wire:replace wire:key="dashboard-distribution-content"', $html);
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($grid, '/').'\s*<article class="ft-mgmt-panel ft-mgmt-flow-prototype"/',
                $html,
            );
        }
        $this->assertStringContainsString('<article class="ft-mgmt-panel">', $loaded);
        $this->assertStringNotContainsString('style="min-width:0;height:100%"', $loaded);

        // The existing stylesheet sizes equal cards only when they are direct grid children.
        $css = file_get_contents(resource_path('theme/flowtrack/components/management-dashboard.css'));
        $this->assertStringContainsString('.ft-mgmt-dashboard-pair-grid>.ft-mgmt-panel', $css);
    }

    public function test_error_states_replace_skeleton_content_without_changing_retry_behavior(): void
    {
        $path = resource_path('views/livewire/dashboard/');
        $error = file_get_contents($path.'panel-error.blade.php');
        $this->assertSame(2, substr_count($error, 'class="ft-mgmt-dashboard-panel-swap"'));
        $this->assertSame(2, substr_count($error, 'wire:replace wire:key='));
        $this->assertSame(2, substr_count($error, 'wire:click="$refresh"'));
        $this->assertSwapBoundary(file_get_contents($path.'overview-error.blade.php'), 'overview');
    }
}
