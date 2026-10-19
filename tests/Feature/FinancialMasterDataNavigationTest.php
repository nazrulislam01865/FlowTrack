<?php

namespace Tests\Feature;

use App\Services\MasterDataService;
use Tests\TestCase;

class FinancialMasterDataNavigationTest extends TestCase
{
    public function test_financial_master_types_are_governed_by_finance_permissions(): void
    {
        foreach (MasterDataService::FINANCIAL_TYPES as $type) {
            $this->assertSame('finance', MasterDataService::permissionModuleForType($type));
        }
    }

    public function test_financial_master_data_has_its_own_protected_navigation_surface(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));

        $this->assertStringContainsString("Route::get('/financial-master-data', MasterDataController::class)", $routes);
        $this->assertStringContainsString("middleware('permission:finance.view')", $routes);
        $this->assertStringContainsString("name('financial-master-data')", $routes);
        $this->assertStringContainsString('Financial Master Data', $sidebar);
        $this->assertStringContainsString("\$financeMasterView = \$user->canModule('finance', 'view')", $sidebar);
        $this->assertStringContainsString('route="financial-master-data"', $sidebar);
    }

    public function test_financial_types_are_not_listed_inside_regular_master_data_menu(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));

        $this->assertStringContainsString("...\$financialGroups", $sidebar);
        $this->assertStringContainsString('$financialLinks', $sidebar);
    }
}
