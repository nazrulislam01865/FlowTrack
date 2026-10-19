<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use App\Models\User;
use App\Services\MasterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MasterDataParentScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_products_keep_a_product_category_parent(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $category = $service->save('product_category', [
            'code' => 'BAGS',
            'name' => 'Bags',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $product = $service->save('product', [
            'code' => 'TOTE',
            'name' => 'Canvas Tote',
            'parent_id' => $category->id,
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $department = $service->save('department', [
            'code' => 'OPS',
            'name' => 'Operations',
            'parent_id' => $category->id,
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $this->assertSame($category->id, $product->parent_id);
        $this->assertNull($department->parent_id);
    }

    public function test_product_parent_must_be_a_product_category_in_the_same_workspace(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $department = $service->save('department', [
            'code' => 'OPS',
            'name' => 'Operations',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $this->expectException(ValidationException::class);

        $service->save('product', [
            'code' => 'INVALID',
            'name' => 'Invalid Product',
            'parent_id' => $department->id,
            'status' => 'active',
            'sort_order' => 1,
        ]);
    }

    public function test_migration_rule_leaves_non_product_records_without_parents(): void
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $category = MasterRecord::create([
            'workspace_id' => $workspaceId,
            'type' => 'product_category',
            'code' => 'CAT',
            'name' => 'Category',
            'status' => 'active',
        ]);
        $priority = MasterRecord::create([
            'workspace_id' => $workspaceId,
            'type' => 'priority',
            'code' => 'HIGH',
            'name' => 'High',
            'parent_id' => $category->id,
            'status' => 'active',
        ]);

        $service = app(MasterDataService::class);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $priority = $service->save('priority', [
            'code' => $priority->code,
            'name' => $priority->name,
            'parent_id' => $category->id,
            'status' => 'active',
            'sort_order' => 0,
        ], $priority->id);

        $this->assertNull($priority->parent_id);
    }
}
