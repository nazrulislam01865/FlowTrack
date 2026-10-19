<?php

namespace Tests\Feature;

use App\Models\MasterRecord;
use App\Models\User;
use App\Services\MasterDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_visible_master_data_type_has_an_automatic_code_prefix(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);

        $this->assertSame(array_keys(MasterDataService::LABELS), array_keys(MasterDataService::CODE_PREFIXES));

        $workspaceId = $service->workspaceId();
        foreach (MasterDataService::CODE_PREFIXES as $type => $prefix) {
            $next = $service->nextCode($type);
            $this->assertMatchesRegularExpression('/^'.preg_quote($prefix, '/').'-\d{3,}$/', $next);
            $this->assertFalse(
                MasterRecord::withTrashed()->where('workspace_id', $workspaceId)->where('type', $type)->where('code', $next)->exists(),
                "Generated code [$next] must be unused."
            );
        }
    }

    public function test_next_code_advances_past_generated_and_soft_deleted_codes(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();

        MasterRecord::create([
            'workspace_id' => $workspaceId,
            'type' => 'product',
            'code' => 'PRD-001',
            'name' => 'Existing Product',
            'status' => 'active',
        ]);

        $deleted = MasterRecord::create([
            'workspace_id' => $workspaceId,
            'type' => 'product',
            'code' => 'PRD-004',
            'name' => 'Deleted Product',
            'status' => 'inactive',
        ]);
        $deleted->delete();

        $this->assertSame('PRD-005', $service->nextCode('product'));
    }

    public function test_product_code_is_generated_while_reference_code_remains_editable(): void
    {
        $view = \Tests\Support\AdministrationPhase7Source::masterDataView();
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $component = \Tests\Support\AdministrationPhase7Source::masterData();

        $this->assertStringContainsString('Generated automatically after the product is created.', $form);
        $this->assertStringContainsString('wire:model.blur="productReferenceCode"', $form);
        $this->assertStringContainsString('Client or supplier reference used for search and matching.', $form);
        $this->assertStringContainsString('$this->code = $service->nextCode($this->group);', $component);

        // Other Master Data types still use the generated locked-code UI.
        $this->assertStringContainsString('<div class="ft-admin-locked">{{ $code }}</div>', $view);
        $this->assertStringContainsString('Automatically generated and permanently locked.', $view);
    }
}
