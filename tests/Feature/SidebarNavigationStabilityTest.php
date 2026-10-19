<?php

namespace Tests\Feature;

use Tests\TestCase;

class SidebarNavigationStabilityTest extends TestCase
{
    public function test_sidebar_is_persisted_across_livewire_navigation(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));
        $sidebar = (string) file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));
        $navLink = (string) file_get_contents(resource_path('views/components/ui/nav-link.blade.php'));

        $this->assertStringContainsString("@persist('flowtrack-sidebar')", $layout);
        $this->assertStringContainsString("asset('js/flowtrack-sidebar-navigation.js')", $layout);
        $this->assertStringContainsString('wire:navigate:scroll', $sidebar);
        $this->assertStringContainsString('data-ft-nav-route="{{ $route }}"', $navLink);
    }

    public function test_persisted_sidebar_keeps_route_and_query_aware_active_state(): void
    {
        $script = (string) file_get_contents(public_path('js/flowtrack-sidebar-navigation.js'));

        $this->assertStringContainsString("['jobs.index', 'inquiries.index', 'clients.index']", $script);
        $this->assertStringContainsString("route === 'master-data'", $script);
        $this->assertStringContainsString("route === 'administration'", $script);
        $this->assertStringContainsString("document.addEventListener('livewire:navigated', sync)", $script);
        $this->assertStringContainsString('group.open = active;', $script);
    }

    public function test_cancelled_order_badge_stays_fresh_with_persisted_sidebar(): void
    {
        $shell = (string) file_get_contents(app_path('Services/ShellDataService.php'));
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $sidebar = (string) file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));

        $this->assertStringContainsString("'cancelled_orders'", $shell);
        $this->assertStringContainsString("'cancelled_order_count'", $routes);
        $this->assertStringContainsString("\$shellData['cancelled_orders']", $sidebar);
        $this->assertStringNotContainsString('CancelledOrderService::class)->sidebarCount($user)', $sidebar);
    }
}
