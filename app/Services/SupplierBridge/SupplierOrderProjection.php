<?php

namespace App\Services\SupplierBridge;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Support\OrderStageResolver;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Deliberately limited supplier-owned snapshot; no customers, margins or internal notes. */
final class SupplierOrderProjection
{
    // Bump when the supplier-visible projection semantics change. Legacy outbox
    // rows are reconciled once; normal writes still deliver event-first.
    public const FORMAT_VERSION = 10;

    private const TASKS = [
        2 => [
            'ART_PREPARE_UPLOAD' => 'Prepare & Upload Artwork',
            'ART_INTERNAL_REVIEW' => 'Internal Artwork Review',
            'ART_SEND_ORDER_TEAM' => 'Send Artwork to Order Team',
            'ART_CLIENT_ERP_DECISION' => 'Client ERP / Approval',
            'ART_SAMPLE_APPROVAL' => 'Sample Approval (when required)',
        ],
        3 => [
            'PROD_SET_ESTIMATED_DELIVERY' => 'Set estimated delivery date',
            'PROD_START' => 'Start Production',
            'PROD_ISSUE' => 'Monitor / Resolve Production Issue',
            'PROD_FINISH' => 'Finish Production',
        ],
        4 => [
            'QC_CHECK' => 'Perform QC Check',
            'QC_ISSUE' => 'Resolve QC Issue (when needed)',
            'QC_APPROVE_SHIPMENT' => 'Approve for Shipment',
        ],
    ];

    public function build(int $orderId, int $supplierId, string $eventId): array
    {
        $order = FlowJob::withTrashed()->with('phase:id,name,short_name,sequence')->withExists('activeHold')->findOrFail($orderId);
        $items = DB::table('flow_job_items as i')
            ->join('master_records as p', 'p.id', '=', 'i.catalog_product_id')
            ->join('master_records as s', 's.id', '=', 'i.supplier_id')
            ->leftJoin('product_supplier_links as l', function ($join): void {
                $join->on('l.product_id', '=', 'p.id')->on('l.supplier_id', '=', 's.id')
                    ->on('l.workspace_id', '=', 'p.workspace_id');
            })
            ->where('i.flow_job_id', $orderId)->where('i.supplier_id', $supplierId)
            ->where('i.is_removed', false)->where('i.quantity', '>', 0)
            ->where('p.type', 'product')->whereNull('p.deleted_at')
            ->where('s.type', 'supplier')->where('s.status', 'active')->whereNull('s.deleted_at')
            ->whereColumn('p.workspace_id', 's.workspace_id')
            ->orderBy('i.id')
            ->get(['i.id', 'i.catalog_product_id', 'p.name as product_name', 'p.code as product_sku', 'p.metadata',
                'i.quantity', 'i.notes', 'l.id as linked_id']);

        // Batch-select assigned supplier prices; never fall back to customer selling prices.
        $overrides = DB::table('supplier_product_prices')->where('supplier_id', $supplierId)
            ->whereIn('product_id', $items->pluck('catalog_product_id'))
            ->pluck('price_breakpoints', 'product_id');
        $lines = [];
        foreach ($items->unique('id') as $item) {
            $product = new MasterRecord();
            $product->setRawAttributes(['type' => 'product', 'metadata' => $item->metadata]);
            if (! $item->linked_id && ! $product->hasProductSupplier($supplierId)) continue;

            $raw = $overrides->get((int) $item->catalog_product_id);
            $rows = $raw !== null ? app(SupplierPricing::class)->normalize(json_decode((string) $raw, true) ?: [])
                : $product->supplierPriceBreakpoints();
            $supplierPrice = null;
            foreach ($rows as $row) {
                if ($row['quantity'] > (int) $item->quantity) break;
                $supplierPrice = (float) $row['price'];
            }
            $unitPrice = $supplierPrice !== null ? round($supplierPrice, 2) : null;
            $lines[] = [
                'source_item_id' => (int) $item->id,
                'product_name' => mb_substr((string) ($item->product_name ?: 'Product'), 0, 255),
                'product_code' => 'PRD-'.str_pad((string) $item->catalog_product_id, 6, '0', STR_PAD_LEFT),
                'sku' => mb_substr((string) ($item->product_sku ?? ''), 0, 100),
                'quantity' => (int) $item->quantity,
                // Supplier unit cost only. Never reveal the customer's order selling price.
                'unit_price' => $unitPrice,
                'notes' => mb_substr((string) ($item->notes ?? ''), 0, 2000),
            ];
        }
        if (count($lines) > 100) throw new RuntimeException('Supplier order has too many assigned lines.');
        $revision = (int) now()->format('Uu');
        if ($lines === [] || $order->trashed() || strcasecmp((string) $order->status, 'Draft') === 0) {
            return ['event_id' => $eventId, 'supplier_id' => $supplierId,
                'order' => ['reference' => $order->displayOrderNumber(), 'revoked' => true, 'revision' => $revision]];
        }

        $resolved = OrderStageResolver::resolve(
            $order->phase?->name, $order->phase?->short_name, $order->phase?->sequence, $order->status,
        );
        $sequence = (int) $resolved['sequence'];
        $names = array_merge(...array_values(self::TASKS));
        // One order-scoped query, no internal comments, assignees, or other suppliers' records.
        $taskRows = DB::table('tasks as t')
            ->join('task_pack_items as pack', 'pack.id', '=', 't.task_pack_task_id')
            ->where('t.flow_job_id', $orderId)->whereNull('t.deleted_at')
            ->whereIn('pack.automation_key', [...array_keys($names), 'ART_SUPPLIER_PO_OPTIONAL'])
            ->orderByDesc('t.id')->get(['t.id', 'pack.automation_key', 't.status',
                't.completed_at', 't.created_at', 't.updated_at']);
        $byKey = $taskRows->unique('automation_key')->keyBy('automation_key');

        $approved = app(ApprovedOrderArtwork::class);
        $artworkConfirmed = $approved->confirmed($sequence, $taskRows, $order->completed_at !== null);
        $stages = [];
        foreach ([2 => 'Artwork', 3 => 'Production', 4 => 'Quality control'] as $index => $name) {
            $state = ($sequence > $index || $order->completed_at !== null) ? 'completed'
                : ($sequence === $index ? 'in_progress' : 'upcoming');
            $stageTasks = [];
            if ($artworkConfirmed) {
                foreach (self::TASKS[$index] as $key => $label) {
                    $task = $byKey->get($key);
                    if (! $task) continue; // Never manufacture a task for a historical order.
                    $status = strtolower(trim((string) $task->status));
                    $taskState = $task->completed_at !== null || $status === 'completed' ? 'completed'
                        : (in_array($status, ['skipped', 'not applicable', 'n/a'], true) ? 'skipped'
                        : ($state === 'upcoming' || in_array($status, ['not started', 'not start'], true) ? 'upcoming'
                        : 'in_progress'));
                    $stageTasks[] = [
                        'name' => $label, 'status' => $taskState,
                        'updated_at' => $task->completed_at ?? $task->updated_at,
                    ];
                }
            }
            $stageTaskRecords = $taskRows->filter(fn ($task) => isset(self::TASKS[$index][$task->automation_key]));
            $completedAt = $stageTaskRecords->pluck('completed_at')->filter()->sort()->last();
            $stages[] = [
                'name' => $name, 'status' => $state,
                'updated_at' => $state === 'completed' ? $completedAt : null,
                'tasks' => $stageTasks,
            ];
        }

        // The current artwork upload batch is approved by workflow tasks, not
        // by documents.is_final. Do not include cancelled or superseded files.
        $documents = $artworkConfirmed ? $approved->documents($orderId, $taskRows)
            ->map(static fn ($file): array => [
                'id' => (int) $file->id,
                'kind' => $file->category === \App\Models\Document::CATEGORY_ARTWORK_TRACKING_PDF ? 'tracking_pdf' : 'artwork',
                'name' => mb_substr(basename(str_replace('\\', '/', (string) $file->name)), 0, 255),
                'size' => (int) $file->size,
                'version' => (int) $file->version,
                'uploaded_at' => $file->created_at,
            ])->all() : [];

        // The audit log is the source for actual changes, not invented dates on stage cards.
        $recent = [];
        if ($artworkConfirmed && $taskRows->isNotEmpty()) {
            $taskIds = $taskRows->pluck('id')->map(fn ($id) => (int) $id)->all();
            $titleById = [];
            foreach ($taskRows as $task) $titleById[(int) $task->id] = $names[$task->automation_key] ?? 'Supplier Purchase Order';
            $activities = DB::table('activities')
                ->where('subject_type', FlowJob::class)->where('subject_id', $orderId)
                ->whereIn('event', ['job.task_activity', 'phase.activated'])
                ->orderByDesc('id')->limit(80)->get(['event', 'meta', 'description', 'created_at']);
            foreach ($activities as $activity) {
                if (count($recent) >= 8) break;
                if ($activity->event === 'phase.activated') {
                    $phase = trim((string) $activity->description);
                    if (preg_match('/^(Artwork|Production|QC|Quality control) activated$/i', $phase, $match)) {
                        $recent[] = [
                            'title' => $match[1].' started', 'status' => 'in_progress',
                            'date' => $activity->created_at,
                        ];
                    }
                    continue;
                }
                $meta = json_decode((string) ($activity->meta ?? ''), true);
                if (! is_array($meta) || ! in_array((int) ($meta['task_id'] ?? 0), $taskIds, true)) continue;
                $status = null;
                if (($meta['field'] ?? '') === 'status') $status = $meta['new'] ?? null;
                elseif (isset($meta['changes']['status'])) $status = $meta['changes']['status']['new'] ?? null;
                if (! is_string($status)) continue;
                $mapped = strtolower(trim($status));
                $taskState = $mapped === 'completed' ? 'completed'
                    : (in_array($mapped, ['skipped', 'not applicable', 'n/a'], true) ? 'skipped' : 'in_progress');
                $recent[] = [
                    'title' => $titleById[(int) $meta['task_id']].' · '.ucwords(str_replace('_', ' ', $mapped)),
                    'status' => $taskState, 'date' => $activity->created_at,
                ];
            }
        }
        $recent[] = [
            'title' => 'Order created', 'status' => 'created',
            'date' => $order->created_at?->toIso8601String(),
        ];

        // No redo lookup for normal orders and no query inside the item/task loops.
        // The unique redo_order_id index and one joined original-order reference
        // supply only operational information for this assigned supplier.
        $redo = null;
        if ($order->start_handling === 'Redo') {
            $record = DB::table('order_redos as redo')
                ->join('flow_jobs as original', 'original.id', '=', 'redo.original_order_id')
                ->where('redo.redo_order_id', $orderId)
                ->first([
                    'original.job_number as original_number',
                    'redo.scope', 'redo.issue_category', 'redo.issue_description',
                    'redo.affected_quantity', 'redo.redo_quantity',
                    'redo.supplier_id', 'redo.supplier_adjustment_type',
                    'redo.supplier_adjustment_value', 'redo.supplier_redo_charge',
                    'redo.freight_amount', 'redo.courier_freight_amount',
                    'redo.total_supplier_recovery',
                ]);
            if ($record) {
                $original = (string) $record->original_number;
                $redo = [
                    'original_reference' => str_starts_with($original, 'JOB-')
                        ? 'ORDER-'.substr($original, 4) : $original,
                    'scope' => (string) $record->scope,
                    'issue_category' => mb_substr((string) $record->issue_category, 0, 120),
                    'issue_description' => mb_substr(
                        app(\App\Services\RichTextService::class)->plainText((string) $record->issue_description),
                        0, 5000,
                    ),
                    'affected_quantity' => (int) $record->affected_quantity,
                    'redo_quantity' => (int) $record->redo_quantity,
                ];
                // An explicitly selected financial supplier always takes precedence.
                // Without one, prefer the redo order's supplier. Historical redo
                // orders can have neither ID (or an outdated order-level ID),
                // despite having one supplier on all active item rows. In that
                // case, check for another assigned supplier once, not per item.
                // Never disclose the recovery when multiple suppliers are possible.
                $selectedSupplierId = (int) $record->supplier_id;
                $financialSupplierId = $selectedSupplierId ?: (int) $order->supplier_id;
                $isFinancialRecipient = $financialSupplierId > 0 && $financialSupplierId === $supplierId;
                if (! $isFinancialRecipient && $selectedSupplierId === 0) {
                    // $items already proves the recipient owns at least one
                    // active assigned line. A single indexed EXISTS rules out
                    // other recipients without loading their order lines.
                    $isFinancialRecipient = ! DB::table('flow_job_items')
                        ->where('flow_job_id', $orderId)
                        ->where('is_removed', false)
                        ->where('quantity', '>', 0)
                        ->whereNotNull('supplier_id')
                        ->where('supplier_id', '!=', $supplierId)
                        ->exists();
                }
                if ($isFinancialRecipient) {
                    $courierFreight = max(0, round((float) $record->courier_freight_amount, 2));
                    $supplierFreight = max(0, round((float) $record->freight_amount - $courierFreight, 2));
                    $redo['supplier_adjustment'] = [
                        'type' => $record->supplier_adjustment_type === 'pcs' ? 'pcs' : 'percent',
                        'value' => (float) $record->supplier_adjustment_value,
                        'charge' => (float) $record->supplier_redo_charge,
                        'freight' => $supplierFreight,
                        'courier_freight' => $courierFreight,
                        'total_recovery' => (float) $record->total_supplier_recovery,
                    ];
                }
            }
        }

        // Only supplier-owned POs are included. Never send a mixed-supplier
        // document, another supplier's financial values, or customer price.
        $purchaseOrders = DB::table('supplier_purchase_orders as po')
            ->leftJoin('documents as doc', 'doc.id', '=', 'po.document_id')
            ->where('po.flow_job_id', $orderId)->where('po.supplier_id', $supplierId)
            ->whereNull('po.archived_at')->orderBy('po.id')->limit(100)
            ->get(['po.id', 'po.po_number', 'po.items_snapshot', 'po.po_delivery_date',
                'po.revision', 'po.document_id', 'po.pdf_name', 'po.email_status', 'doc.size'])
            ->map(static function ($po): array {
                $items = json_decode((string) $po->items_snapshot, true);
                return [
                    'id' => (int) $po->id, 'number' => (string) $po->po_number,
                    'revision' => (int) $po->revision,
                    'delivery_date' => $po->po_delivery_date ? substr((string) $po->po_delivery_date, 0, 10) : null,
                    'status' => (string) $po->email_status,
                    'items' => array_values(array_map(static fn (array $line): array => [
                        'item_id' => (int) $line['item_id'],
                        'name' => (string) $line['name'], 'sku' => (string) ($line['sku'] ?? ''),
                        'quantity' => (int) $line['quantity'],
                        'supplier_unit_cost' => $line['supplier_unit_cost'] === null ? null : (float) $line['supplier_unit_cost'],
                    ], is_array($items) ? $items : [])),
                    'document' => $po->document_id && $po->size > 0 ? [
                        'id' => (int) $po->document_id,
                        'name' => basename(str_replace('\\', '/', (string) $po->pdf_name)),
                        'size' => (int) $po->size,
                    ] : null,
                ];
            })->all();
        $poTask = $byKey->get('ART_SUPPLIER_PO_OPTIONAL');
        $canCreatePo = $sequence === 2 && ! $order->active_hold_exists
            && $poTask && ! $poTask->completed_at
            && ! in_array(strtolower((string) $poTask->status), ['skipped', 'completed'], true)
            && $purchaseOrders === [] && $artworkConfirmed
            && ($client = $byKey->get('ART_CLIENT_ERP_DECISION'))
            && ($client->completed_at || strtolower((string) $client->status) === 'completed');

        return ['event_id' => $eventId, 'supplier_id' => $supplierId, 'order' => [
            'reference' => $order->displayOrderNumber(),
            'title' => mb_substr(trim((string) $order->title) ?: ('Order '.$order->displayOrderNumber()), 0, 255),
            'source_id' => (int) $orderId, 'revision' => $revision,
            'created_at' => $order->created_at?->toIso8601String(),
            'stage_sequence' => $sequence,
            'stage_name' => $resolved['name'],
            'stage_color' => $resolved['color'],
            'progress' => max(0, min(100, (int) ($order->progress ?? 0))),
            'on_hold' => (bool) $order->active_hold_exists,
            'delivery_date' => $order->supplier_delivery_date?->toDateString()
                ?? $order->estimated_delivery_date?->toDateString(),
            // Explicit buyer-controlled supplier ship date; never substitute the
            // customer estimated delivery date as if it were a confirmed ship date.
            'supplier_ship_date' => $order->supplier_delivery_date?->toDateString(),
            // Production's FlowTracker-managed target is not the supplier ship date.
            'estimated_delivery_date' => $order->estimated_delivery_date?->toDateString(),
            'status' => mb_substr((string) $order->status, 0, 80),
            'currency' => preg_match('/^[A-Z]{3}$/', strtoupper((string) $order->currency))
                ? strtoupper((string) $order->currency) : 'USD',
            'supplier_instruction' => mb_substr((string) ($order->supplier_instruction ?? ''), 0, 2000),
            ...($redo !== null ? ['redo' => $redo] : []),
            'items' => $lines, 'stages' => $stages,
            'purchase_orders' => $purchaseOrders, 'po_creation_available' => (bool) $canCreatePo,
            'workflow' => app(SupplierWorkflowProjection::class)->build($order, $supplierId), 'documents' => $documents,
            'recent_updates' => $recent,
        ]];
    }
}
