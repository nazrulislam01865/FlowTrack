<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MasterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataCreatorColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_record_keeps_its_original_creator_when_edited(): void
    {
        $creator = User::factory()->create(['is_super_admin' => true]);
        $editor = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($creator);
        $service = app(MasterDataService::class);

        $product = $service->save('product', [
            'code' => 'PRD-CREATOR',
            'name' => 'Creator Test Product',
            'status' => 'active',
            'sort_order' => 0,
        ]);

        $this->assertSame($creator->id, $product->created_by);

        $this->actingAs($editor);
        $product = $service->save('product', [
            'code' => $product->code,
            'name' => 'Creator Test Product Updated',
            'status' => 'active',
            'sort_order' => 0,
        ], $product->id);

        $this->assertSame($creator->id, $product->fresh()->created_by);
    }

    public function test_product_list_and_detail_expose_update_and_creation_audit_metadata(): void
    {
        $view = \Tests\Support\AdministrationPhase7Source::masterDataView();
        $detail = file_get_contents(resource_path('views/components/catalog/product-view.blade.php'));

        $this->assertStringContainsString('<th>Updated</th>', $view);
        $this->assertStringContainsString('ft-product-updated', $view);
        $this->assertStringContainsString("Created by {{ \$product->creator?->name ?? '—' }}", $detail);
        $this->assertStringContainsString("\$created?->format('M j, Y g:i A')", $detail);
    }
}
