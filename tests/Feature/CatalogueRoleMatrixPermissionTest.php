<?php

namespace Tests\Feature;

use App\Services\AccessControlService;
use App\Services\MasterDataService;
use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class CatalogueRoleMatrixPermissionTest extends TestCase
{
    public function test_catalogue_management_has_separate_role_matrix_modules(): void
    {
        $expected = [
            'catalog_products' => 'Products',
            'product_categories' => 'Product Categories',
            'suppliers' => 'Suppliers',
        ];

        foreach ($expected as $module => $name) {
            $this->assertArrayHasKey($module, AccessControlService::MODULES);
            $this->assertSame($name, AccessControlService::MODULES[$module]['name']);
            $this->assertSame('Catalogue', AccessControlService::MODULES[$module]['group']);
            $this->assertSame(AccessControlService::ACTIONS, AccessControlService::supportedActions($module));
            $this->assertTrue(AccessControlService::isUniversalRecordModule($module));
            $this->assertTrue(AccessControlService::supportsScope($module));
        }
    }

    public function test_master_data_types_map_to_their_own_management_permissions(): void
    {
        $this->assertSame('catalog_products', MasterDataService::permissionModuleForType('product'));
        $this->assertSame('product_categories', MasterDataService::permissionModuleForType('product_category'));
        $this->assertSame('suppliers', MasterDataService::permissionModuleForType('supplier'));
        $this->assertSame('masterdata', MasterDataService::permissionModuleForType('priority'));
    }

    public function test_sidebar_promotes_catalogue_links_and_removes_them_from_master_data_children(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));

        $this->assertStringContainsString("label=\"Products\" icon=\"products\"", $sidebar);
        $this->assertStringContainsString("label=\"Product Categories\" icon=\"categories\"", $sidebar);
        $this->assertStringContainsString("label=\"Suppliers\" icon=\"suppliers\"", $sidebar);
        $this->assertStringContainsString("collect(\$masterLabels)->except([...\$catalogueGroups, ...\$financialGroups, ...\$taskPackMasterGroups, 'task_status', 'task_flag'])->all()", $sidebar);
        $this->assertStringContainsString("canModule('catalog_products', 'view')", $sidebar);
        $this->assertStringContainsString("canModule('product_categories', 'view')", $sidebar);
        $this->assertStringContainsString("canModule('suppliers', 'view')", $sidebar);
    }

    public function test_inline_catalogue_creation_uses_product_and_category_permissions_separately(): void
    {
        $jobs = OrderPhase5Source::livewire();
        $inquiries = $this->inquiryLivewireSource();
        $master = \Tests\Support\AdministrationPhase7Source::masterData();
        $service = file_get_contents(app_path('Services/MasterDataService.php'));

        foreach ([$jobs, $inquiries] as $component) {
            $this->assertStringContainsString("canModule('catalog_products', 'create')", $component);
            $this->assertStringContainsString("canModule('product_categories', 'create')", $component);
        }

        $this->assertStringContainsString("canModule('catalog_products', 'edit')", $master);
        $this->assertStringContainsString("canModule('catalog_products', 'delete')", $master);
        $this->assertStringContainsString("canModule('product_categories', 'create')", $master);
        $this->assertStringContainsString('permissionModuleForType($type)', $service);
    }

    public function test_create_inquiry_and_order_catalogue_access_is_controlled_by_products_not_product_lines(): void
    {
        $filterOptions = file_get_contents(app_path('Services/FilterOptionService.php'));
        $inquiries = $this->inquiryLivewireSource();
        $jobs = OrderPhase5Source::livewire();
        $jobTable = file_get_contents(resource_path('views/components/jobs/table.blade.php'));
        $inquiryProducts = file_get_contents(resource_path('views/components/inquiries/create-products.blade.php'));
        $orderProducts = OrderPhase5Source::createProductsView();
        $imageController = file_get_contents(app_path('Http/Controllers/ProductImageController.php'));

        $this->assertStringContainsString("'create-job' => \$user->canModule('jobs', 'create')", $filterOptions);
        $this->assertStringContainsString("'create-inquiry' => \$user->canModule('inquiries', 'create')", $filterOptions);
        $this->assertStringContainsString("canModule('catalog_products', 'view')", $filterOptions);
        $this->assertStringContainsString("canModule('product_categories', 'view')", $filterOptions);

        $this->assertStringContainsString("return \$user->canModule('inquiries', 'create')\n            && \$user->canModule('catalog_products', 'view');", $inquiries);
        $this->assertStringContainsString("return \$user->canModule('jobs', 'create')\n            && \$user->canModule('catalog_products', 'view');", $jobs);
        $this->assertStringNotContainsString("'products' => ['name' => 'Inquiry / Order Product Lines'", file_get_contents(app_path('Services/AccessControlService.php')));
        $this->assertStringContainsString('this legacy preload was unused and could turn a hidden optional control', $inquiries);
        $this->assertStringContainsString("abort_unless(auth()->user()->canModule('jobs', 'create'), 403);", $jobs);
        $this->assertStringContainsString("@if(auth()->user()->canModule('jobs', 'create'))", $jobTable);

        $this->assertStringContainsString('@if($canViewProductCategories)', $inquiryProducts);
        $this->assertStringContainsString('@if($canViewProductCategories)', $orderProducts);
        $this->assertStringContainsString('@if($catalogReady && $canUseOrderProductSelector)', $orderProducts);
        $this->assertStringContainsString('Products → View', $orderProducts);

        $this->assertStringContainsString("canModule('catalog_products', 'view')", $imageController);
        $this->assertStringNotContainsString("canModule('inquiries', 'create')", $imageController);
        $this->assertStringNotContainsString("canAccess('jobs.create')", $imageController);
    }

    public function test_migration_copies_existing_master_data_rights_for_safe_upgrade(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_08_13_103700_add_catalogue_role_matrix_modules.php'));

        $this->assertStringContainsString("where('module_code', 'masterdata')", $migration);
        $this->assertStringContainsString("['catalog_products', 'product_categories', 'suppliers']", $migration);
        $this->assertStringContainsString("'record_scope' => \$actions ? 'all_records' : 'none'", $migration);
    }
}
