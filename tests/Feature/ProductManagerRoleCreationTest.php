<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AccessControlService;
use App\Services\AdminService;
use App\Services\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductManagerRoleCreationTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
    }

    private function save(string $name, User $admin, string $code = ''): Role
    {
        return app(AdminService::class)->saveRole([
            'name' => $name,
            'code' => $code,
            'description' => '',
            'default_scope' => 'assigned_jobs',
            'is_active' => true,
        ], null, $admin);
    }

    public function test_product_manager_gets_only_product_catalogue_capabilities(): void
    {
        $role = $this->save('Product Manager', $this->administrator());

        $this->assertSame('product-manager', $role->slug);
        $this->assertSame('PRODUCT_MANAGER', $role->code);
        $this->assertFalse($role->is_system);

        foreach (AccessControlService::MODULES as $module => $_) {
            $access = RoleModuleAccess::query()
                ->where('role_id', $role->id)
                ->where('module_code', $module)
                ->firstOrFail();

            if (in_array($module, ['catalog_products', 'product_categories'], true)) {
                $this->assertSame(AccessControlService::supportedActions($module), $access->actions);
                $this->assertSame('all_records', $access->record_scope);
            } else {
                $this->assertSame([], $access->actions, 'Unexpected permission for '.$module);
                $this->assertSame('none', $access->record_scope);
            }
        }
    }

    public function test_assigned_product_manager_can_edit_products_but_cannot_read_confidential_or_unrelated_modules(): void
    {
        $role = $this->save('Product Manager', $this->administrator());
        $manager = User::factory()->create([
            'role_id' => $role->id,
            'is_super_admin' => false,
            'is_active' => true,
        ]);
        $manager->roles()->sync([$role->id]);
        $access = app(AccessControlService::class);

        $this->assertTrue($access->can($manager, 'catalog_products', 'manage'));
        $this->assertTrue($access->can($manager, 'catalog_products', 'edit'));
        $this->assertTrue($access->can($manager, 'product_categories', 'create'));
        foreach (['supplier_pricing', 'finance', 'suppliers', 'jobs', 'users', 'dashboard'] as $module) {
            $this->assertFalse($access->can($manager, $module, 'view'), 'Unexpected access to '.$module);
        }
    }

    public function test_duplicate_in_current_workspace_returns_validation_error_without_changing_existing_role(): void
    {
        $admin = $this->administrator();
        $original = $this->save('Product Manager', $admin);
        $count = RoleModuleAccess::query()->where('role_id', $original->id)->count();

        try {
            $this->save('Product manager', $admin, 'PRODUCT_MANAGER');
            $this->fail('Expected a user-visible duplicate role validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('roleName', $exception->errors());
        }

        $this->assertSame(1, Role::where('workspace_id', 1)->where('slug', 'product-manager')->count());
        $this->assertSame($count, RoleModuleAccess::where('role_id', $original->id)->count());
    }

    public function test_global_slug_collision_in_another_workspace_does_not_block_local_creation(): void
    {
        $admin = $this->administrator();
        $first = $this->save('Product Manager', $admin);
        $secondWorkspace = Workspace::create([
            'name' => 'Another workspace',
            'slug' => 'another-workspace',
            'timezone' => 'Asia/Dhaka',
            'default_currency' => 'USD',
            'is_active' => true,
        ]);
        app(WorkspaceContext::class)->set($secondWorkspace->id);
        $second = $this->save('Product Manager', $admin);

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame('product-manager-ws'.$secondWorkspace->id, $second->slug);
        $this->assertSame((int) $secondWorkspace->id, (int) $second->workspace_id);
        $this->assertSame('all_records', $second->moduleAccess()->where('module_code', 'catalog_products')->value('record_scope'));
    }

    public function test_product_only_login_and_logo_link_have_authorized_catalogue_fallback(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Auth/AuthController.php'));
        $sidebar = file_get_contents(resource_path('views/layouts/partials/sidebar.blade.php'));

        $this->assertStringContainsString("canModule('catalog_products', 'view')", $controller);
        $this->assertStringContainsString("route('master-data', ['group' => 'product'])", $controller);
        $this->assertStringContainsString("return redirect()->intended(route('dashboard'));", $controller);
        $this->assertStringContainsString('!$user->canAccess(\'dashboard.view\') && $catalogProductView', $sidebar);
    }

    public function test_unrelated_new_roles_keep_all_permissions_disabled(): void
    {
        $role = $this->save('Review Coordinator', $this->administrator());
        $this->assertSame(count(AccessControlService::MODULES), $role->moduleAccess()->count());
        $this->assertSame(0, $role->moduleAccess()->where('record_scope', '!=', 'none')->count());
    }

    public function test_reserved_administrator_identity_cannot_be_created_as_a_normal_role(): void
    {
        $this->expectException(ValidationException::class);
        $this->save('Admin', $this->administrator(), 'administrator');
    }
}
