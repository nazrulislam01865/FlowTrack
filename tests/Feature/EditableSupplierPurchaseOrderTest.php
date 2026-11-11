<?php

namespace Tests\Feature;

use App\Models\FlowJob;
use App\Services\OrderWorkflowSetupService;
use App\Services\Orders\SupplierPurchaseOrderService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regression checks for the optional Artwork PO step and PO-only editing. */
class EditableSupplierPurchaseOrderTest extends TestCase
{
    private function validateItem(array $original, array $edits): array
    {
        return (new \ReflectionMethod(SupplierPurchaseOrderService::class, 'validatedItem'))
            ->invoke(app(SupplierPurchaseOrderService::class), $original, $edits);
    }

    private function validateDate(FlowJob $job, ?string $date): ?string
    {
        return (new \ReflectionMethod(SupplierPurchaseOrderService::class, 'validatedDeliveryDate'))
            ->invoke(app(SupplierPurchaseOrderService::class), $job, $date);
    }

    public function test_optional_supplier_po_step_comes_immediately_after_sample_approval(): void
    {
        $stages = OrderWorkflowSetupService::fixedStages();
        $artwork = collect($stages)->firstWhere('key', 'art');
        $keys = array_column($artwork['tasks'], 'automation_key');
        $position = array_search('ART_SAMPLE_APPROVAL', $keys, true);

        $this->assertNotFalse($position);
        $this->assertSame('ART_SUPPLIER_PO_OPTIONAL', $keys[$position + 1] ?? null);
        $this->assertFalse((bool) $artwork['tasks'][$position + 1]['is_required']);
        $this->assertContains('NEW_UPLOAD_PO', array_column($stages[0]['tasks'], 'automation_key'));
    }

    public function test_po_changes_do_not_modify_the_original_order_values(): void
    {
        $original = [
            'item_id' => 31, 'name' => 'Original product', 'sku' => 'SKU-100',
            'quantity' => 150, 'supplier_unit_cost' => 0.0, 'supplier_id' => 17,
        ];
        $edited = $this->validateItem($original, [
            'name' => 'Updated model name', 'sku' => 'SKU-200',
            'quantity' => 120, 'supplier_unit_cost' => 4.50,
        ]);

        $this->assertSame('Original product', $original['name']);
        $this->assertSame(150, $original['quantity']);
        $this->assertSame('Updated model name', $edited['name']);
        $this->assertSame(120, $edited['quantity']);
        $this->assertSame(4.5, $edited['supplier_unit_cost']);
        $this->assertSame(31, $edited['item_id']);
        $this->assertSame(17, $edited['supplier_id']);
    }

    public function test_historic_purchase_order_lines_without_sku_can_be_edited(): void
    {
        $oldRow = [
            'item_id' => 9, 'name' => 'Historic item', 'quantity' => 50,
            'supplier_unit_cost' => null, 'supplier_id' => 2,
        ];
        $edited = $this->validateItem($oldRow, [
            'name' => 'Historic item', 'sku' => 'NEW-MODEL',
            'quantity' => 40, 'supplier_unit_cost' => 0,
        ]);
        $this->assertSame('NEW-MODEL', $edited['sku']);
        $this->assertSame(0.0, $edited['supplier_unit_cost']);
        $this->assertSame(50, $oldRow['quantity']);
    }

    public function test_invalid_quantity_is_rejected_server_side(): void
    {
        $this->expectException(ValidationException::class);
        $this->validateItem([
            'item_id' => 1, 'name' => 'Item', 'sku' => '',
            'quantity' => 1, 'supplier_unit_cost' => 3,
        ], ['quantity' => 0]);
    }

    public function test_po_date_may_precede_order_delivery_date_but_not_follow_it(): void
    {
        $job = new FlowJob();
        $job->delivery_date = '2026-12-01';
        $this->assertSame('2026-11-20', $this->validateDate($job, '2026-11-20'));
        $this->assertSame('2026-12-01', $job->delivery_date->format('Y-m-d'));

        $this->expectException(ValidationException::class);
        $this->validateDate($job, '2026-12-02');
    }
}
