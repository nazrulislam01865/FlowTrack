<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\SupplierPurchaseOrder;
use App\Models\SupplierTaskAssignment;
use App\Models\Task;
use App\Services\Orders\SupplierPurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Supplier-browser mutations are accepted only via the server-signed, supplier-scoped bridge. */
final class ReceiveSupplierPurchaseOrder extends Controller
{
    use VerifySupplierQuotation;

    public function __invoke(Request $request, SupplierPurchaseOrderService $service): JsonResponse
    {
        $data = $this->signedQuotation($request);
        $input = Validator::make($data, [
            'event_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'order_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:create,update'],
            'po_id' => ['required_if:action,update', 'nullable', 'integer', 'min:1'],
            'revision' => ['required_if:action,update', 'nullable', 'integer', 'min:1'],
            'mode' => ['nullable', 'in:single,split'],
            'split_item_ids' => ['sometimes', 'array', 'max:100'],
            'split_item_ids.*' => ['integer', 'min:1', 'distinct'],
            'template' => ['sometimes', 'array', 'max:30'],
            'template.*' => ['nullable', 'string', 'max:255'],
            'delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'items.*.supplier_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
        ])->validate();

        $supplier = MasterRecord::query()->ofType('supplier')->where('status', 'active')
            ->findOrFail((int) $input['supplier_id']);
        $order = FlowJob::query()->findOrFail((int) $input['order_id']);
        abort_if(strcasecmp((string) $order->status, 'Cancelled') === 0, 409, 'Order was cancelled.');
        // Unlike a browser route, signed supplier ID is the only acting identity.
        // Cross-check workspace and every line against CURRENT Order assignments.
        $ownedIds = DB::table('flow_job_items as item')
            ->join('master_records as product', 'product.id', '=', 'item.catalog_product_id')
            ->where('item.flow_job_id', $order->id)->where('item.supplier_id', $supplier->id)
            ->where('item.is_removed', false)->where('item.quantity', '>', 0)
            ->where('product.type', 'product')->where('product.workspace_id', $supplier->workspace_id)
            ->whereNull('product.deleted_at')
            ->pluck('item.id')->map(fn ($id) => (int) $id)->all();
        abort_unless($ownedIds, 403);
        $rows = collect($input['items']);
        $submitted = $rows->pluck('item_id')->map(fn ($id) => (int) $id)->all();
        abort_unless(! array_diff($submitted, $ownedIds), 403, 'Products are not assigned to this supplier.');
        $edits = $rows->mapWithKeys(fn (array $row): array => [(int) $row['item_id'] => [
            'name' => $row['name'], 'sku' => $row['sku'] ?? '',
            'quantity' => $row['quantity'], 'supplier_unit_cost' => $row['supplier_unit_cost'] ?? null,
        ]])->all();

        if ($input['action'] === 'create') {
            // Only a supplier assigned to the optional Artwork PO task can generate POs.
            // The service independently validates the task stage and predecessors under the Order lock.
            $optionalTaskId = Task::query()->where('flow_job_id', $order->id)
                ->where('workflow_phase_id', $order->workflow_phase_id)
                ->whereHas('setupTemplate', fn ($q) => $q->where('automation_key', 'ART_SUPPLIER_PO_OPTIONAL'))
                ->value('id');
            abort_unless($optionalTaskId && SupplierTaskAssignment::query()
                ->where('flow_job_id', $order->id)->where('task_id', $optionalTaskId)
                ->where('supplier_id', $supplier->id)->exists(), 403,
                'This supplier is not assigned to create the Purchase Order.');
            abort_unless(count($submitted) === count($ownedIds) && ! array_diff($ownedIds, $submitted), 422,
                'Include all products assigned to this supplier.');
            $mode = (string) ($input['mode'] ?? 'single');
            $splitIds = array_map('intval', (array) ($input['split_item_ids'] ?? []));
            abort_unless(! array_diff($splitIds, $ownedIds), 403);
            $created = $service->generate($order, null, $mode, $splitIds,
                (array) ($input['template'] ?? []), $edits,
                $input['delivery_date'] ?? null, (int) $supplier->id);
            $po = $created[0];
        } else {
            $po = SupplierPurchaseOrder::query()->where('flow_job_id', $order->id)
                ->where('supplier_id', $supplier->id)->whereNull('archived_at')
                ->findOrFail((int) $input['po_id']);
            $poItems = collect((array) $po->items_snapshot)->pluck('item_id')->map(fn ($id) => (int) $id)->all();
            abort_unless(count($submitted) === count($poItems) && ! array_diff($submitted, $poItems)
                && ! array_diff($poItems, $submitted) && ! array_diff($poItems, $ownedIds), 403);
            try {
                $po = $service->update($order, (int) $po->id, null, $edits,
                    $input['delivery_date'] ?? null, (int) $input['revision']);
            } catch (ValidationException $exception) {
                if (isset($exception->errors()['orderWorkflowActionPayload.supplier_po_edit'])) {
                    return response()->json(['message' => 'The Purchase Order changed. Refresh before editing again.'], 409);
                }
                throw $exception;
            }
        }

        $records = $input['action'] === 'create' ? $created : [$po];
        $projection = collect($records)->map(function (SupplierPurchaseOrder $record): array {
            $record->load('document:id,name,size');
            return [
                'id' => (int) $record->id, 'number' => (string) $record->po_number,
                'revision' => (int) $record->revision,
                'delivery_date' => $record->po_delivery_date?->format('Y-m-d'),
                'status' => (string) $record->email_status,
                'items' => collect($record->items_snapshot)->map(fn (array $line): array => [
                    'item_id' => (int) $line['item_id'], 'name' => (string) $line['name'],
                    'sku' => (string) ($line['sku'] ?? ''), 'quantity' => (int) $line['quantity'],
                    'supplier_unit_cost' => $line['supplier_unit_cost'] === null ? null : (float) $line['supplier_unit_cost'],
                ])->all(),
                'document' => $record->document ? [
                    'id' => (int) $record->document->id, 'name' => (string) $record->document->name,
                    'size' => (int) $record->document->size,
                ] : null,
            ];
        })->values()->all();
        return response()->json(['received' => true, 'po' => $projection[0], 'pos' => $projection]);
    }
}
