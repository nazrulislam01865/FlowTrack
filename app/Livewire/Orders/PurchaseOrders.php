<?php

namespace App\Livewire\Orders;

use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\SupplierPurchaseOrder;
use App\Services\AccessControlService;
use App\Services\Orders\OrderReadService;
use App\Services\Orders\SupplierPurchaseOrderService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Supplier PO workspace. The optional Artwork step only records a PO when the user explicitly generates it. */
final class PurchaseOrders extends Component
{
    use WithPagination;

    #[Url(as: 'order', history: true)]
    public ?int $orderId = null;

    #[Url(as: 'search', except: '')]
    public string $search = '';

    #[Url(as: 'po', except: 'all')]
    public string $poFilter = 'all';

    #[Url(as: 'create', except: false)]
    public bool $create = false;

    public array $orderWorkflowActionPayload = [];
    public array $orderWorkflowActionModalPreview = [];

    public function boot(): void
    {
        // Block both initial mounts and stale Livewire update requests while disabled.
        abort_unless(config('flowtrack.supplier_po_creation_enabled', false), 404);
    }

    public function mount(): void
    {
        abort_unless(auth()->user()?->canAccess('jobs.view'), 403);
        if ($this->orderId) {
            $this->prepare($this->visibleOrder());
        }
    }

    private function visibleOrder(): FlowJob
    {
        abort_unless($this->orderId && $this->orderId > 0, 404);
        return app(OrderReadService::class)->visibleQuery(auth()->user())
            ->with(['client', 'phase:id,name,color'])->findOrFail($this->orderId);
    }

    private function editableOrder(): FlowJob
    {
        $order = $this->visibleOrder();
        abort_unless(app(AccessControlService::class)->canEditVisibleJob(auth()->user(), $order), 403);
        abort_if(strcasecmp((string) $order->status, 'Cancelled') === 0, 422, 'Purchase Orders cannot be changed for cancelled Orders.');
        return $order;
    }

    private function prepare(FlowJob $order): void
    {
        $service = app(SupplierPurchaseOrderService::class);
        $rows = $service->rows($order);
        $products = $service->products($order);
        $this->orderWorkflowActionModalPreview['supplier_po'] = [
            // Keep the Order's line items available for identification even
            // after PO generation; fetch once on selection, not on every render.
            'products' => $products,
            'rows' => $rows,
        ];
        $this->orderWorkflowActionPayload = [
            'supplier_po_mode' => 'single',
            'supplier_po_item_ids' => [],
            'supplier_po_template' => [],
            'supplier_po_delivery_date' => ($order->supplier_delivery_date ?: $order->delivery_date)?->format('Y-m-d') ?: '',
            'supplier_po_items' => collect($products)->mapWithKeys(static fn (array $product): array => [
                $product['id'] => [
                    'name' => $product['name'], 'sku' => $product['sku'],
                    'quantity' => $product['quantity'], 'supplier_unit_cost' => $product['supplier_unit_cost'],
                ],
            ])->all(),
            'supplier_po_editing_id' => 0,
            'supplier_po_edit_items' => [],
            'supplier_po_edit_delivery_date' => '',
            'supplier_po_edit_revision' => 0,
            'supplier_po_selected_id' => (int) ($rows[0]['id'] ?? 0),
            'supplier_po_recipients' => collect($rows)->mapWithKeys(static function (array $row): array {
                $recipients = $row['recipients'] ?: array_filter([(string) $row['email']]);
                return [(int) $row['id'] => array_values($recipients) ?: ['']];
            })->all(),
        ];
    }

    public function selectOrder(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->create = false;
        $this->prepare($this->visibleOrder());
        $this->resetValidation();
    }

    public function cancelSupplierPoCreation(): void
    {
        $this->create = false;
        $this->resetValidation();
    }

    public function closeOrderWorkflowAction(): void
    {
        $this->create = false;
        $this->orderId = null;
        $this->orderWorkflowActionPayload = [];
        $this->orderWorkflowActionModalPreview = [];
        $this->resetValidation();
    }

    public function updatedSearch(): void
    {
        $this->resetPage('poPage');
    }

    public function setPoFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['all', 'pending', 'generated'], true), 422);
        $this->poFilter = $filter;
        $this->resetPage('poPage');
    }

    public function clearPoFilters(): void
    {
        $this->search = '';
        $this->poFilter = 'all';
        $this->resetPage('poPage');
    }

    public function chooseSupplierPurchaseOrderMode(string $mode): void
    {
        abort_unless(in_array($mode, ['single', 'split'], true), 422);
        $this->orderWorkflowActionPayload['supplier_po_mode'] = $mode;
        $this->resetValidation('orderWorkflowActionPayload.supplier_po_item_ids');
    }

    public function selectSupplierPurchaseOrder(int $poId): void
    {
        $ids = array_column((array) data_get($this->orderWorkflowActionModalPreview, 'supplier_po.rows', []), 'id');
        abort_unless(in_array($poId, array_map('intval', $ids), true), 422);
        $this->orderWorkflowActionPayload['supplier_po_selected_id'] = $poId;
        $this->cancelSupplierPurchaseOrderEdit();
    }

    public function beginSupplierPurchaseOrderEdit(int $poId): void
    {
        $this->editableOrder();
        $this->selectSupplierPurchaseOrder($poId);
        $row = collect((array) data_get($this->orderWorkflowActionModalPreview, 'supplier_po.rows', []))
            ->firstWhere('id', $poId);
        abort_unless($row, 404);
        $this->orderWorkflowActionPayload['supplier_po_editing_id'] = $poId;
        $this->orderWorkflowActionPayload['supplier_po_edit_revision'] = (int) $row['revision'];
        $this->orderWorkflowActionPayload['supplier_po_edit_delivery_date'] = (string) ($row['delivery_date'] ?? '');
        $this->orderWorkflowActionPayload['supplier_po_edit_items'] = collect($row['item_rows'])
            ->mapWithKeys(static fn (array $item): array => [(int) $item['item_id'] => [
                'name' => (string) $item['name'], 'sku' => (string) ($item['sku'] ?? ''),
                'quantity' => (int) $item['quantity'], 'supplier_unit_cost' => $item['supplier_unit_cost'],
            ]])->all();
        $this->resetValidation();
    }

    public function cancelSupplierPurchaseOrderEdit(): void
    {
        $this->orderWorkflowActionPayload['supplier_po_editing_id'] = 0;
        $this->orderWorkflowActionPayload['supplier_po_edit_items'] = [];
        $this->orderWorkflowActionPayload['supplier_po_edit_delivery_date'] = '';
        $this->orderWorkflowActionPayload['supplier_po_edit_revision'] = 0;
        $this->resetValidation();
    }

    public function saveSupplierPurchaseOrderEdit(): void
    {
        $order = $this->editableOrder();
        $poId = (int) ($this->orderWorkflowActionPayload['supplier_po_editing_id'] ?? 0);
        abort_unless($poId > 0, 422, 'Select a Purchase Order to edit.');
        $this->validate([
            'orderWorkflowActionPayload.supplier_po_edit_items' => ['required', 'array', 'min:1'],
            'orderWorkflowActionPayload.supplier_po_edit_items.*.name' => ['required', 'string', 'max:255'],
            'orderWorkflowActionPayload.supplier_po_edit_items.*.sku' => ['nullable', 'string', 'max:100'],
            'orderWorkflowActionPayload.supplier_po_edit_items.*.quantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'orderWorkflowActionPayload.supplier_po_edit_items.*.supplier_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'orderWorkflowActionPayload.supplier_po_edit_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'orderWorkflowActionPayload.supplier_po_edit_revision' => ['required', 'integer', 'min:1'],
        ]);
        try {
            app(SupplierPurchaseOrderService::class)->update(
                $order, $poId, auth()->user(),
                (array) $this->orderWorkflowActionPayload['supplier_po_edit_items'],
                (string) ($this->orderWorkflowActionPayload['supplier_po_edit_delivery_date'] ?? ''),
                (int) $this->orderWorkflowActionPayload['supplier_po_edit_revision'],
            );
        } catch (ValidationException $exception) {
            $this->showErrors($exception);
            return;
        }
        $this->prepare($order);
        $this->orderWorkflowActionPayload['supplier_po_selected_id'] = $poId;
        session()->flash('success', 'Purchase Order saved. The updated PDF is ready; sending it is optional.');
    }

    public function addSupplierPoRecipient(int $poId): void
    {
        $this->selectSupplierPurchaseOrder($poId);
        $recipients = (array) ($this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] ?? ['']);
        if (count($recipients) < 20) {
            $recipients[] = '';
            $this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] = array_values($recipients);
        }
    }

    public function removeSupplierPoRecipient(int $poId, int $index): void
    {
        $this->selectSupplierPurchaseOrder($poId);
        $recipients = (array) ($this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] ?? ['']);
        if ($index > 0 && array_key_exists($index, $recipients)) {
            unset($recipients[$index]);
            $this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] = array_values($recipients);
        }
    }

    private function recipientEmails(int $poId): string
    {
        return implode(', ', array_map(
            static fn ($value) => trim((string) $value),
            (array) ($this->orderWorkflowActionPayload['supplier_po_recipients'][$poId] ?? []),
        ));
    }

    private function showErrors(ValidationException $exception): void
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) $this->addError($field, $message);
        }
    }

    public function createSupplierPurchaseOrders(): void
    {
        abort_unless($this->create && config('flowtrack.supplier_po_creation_enabled', false), 403,
            'Use Create PO in the optional Artwork task.');
        $order = $this->editableOrder();
        // Never trust a direct Livewire call or URL parameter as task authorization.
        $this->validate([
            'orderWorkflowActionPayload.supplier_po_mode' => ['required', 'in:single,split'],
            'orderWorkflowActionPayload.supplier_po_item_ids' => ['array'],
            'orderWorkflowActionPayload.supplier_po_item_ids.*' => ['integer', 'distinct'],
            'orderWorkflowActionPayload.supplier_po_template' => ['array'],
            'orderWorkflowActionPayload.supplier_po_template.*' => ['nullable', 'string', 'max:255'],
            'orderWorkflowActionPayload.supplier_po_items' => ['required', 'array', 'min:1'],
            'orderWorkflowActionPayload.supplier_po_items.*.name' => ['required', 'string', 'max:255'],
            'orderWorkflowActionPayload.supplier_po_items.*.sku' => ['nullable', 'string', 'max:100'],
            'orderWorkflowActionPayload.supplier_po_items.*.quantity' => ['required', 'integer', 'min:1', 'max:999999999'],
            'orderWorkflowActionPayload.supplier_po_items.*.supplier_unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'orderWorkflowActionPayload.supplier_po_delivery_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        try {
            app(SupplierPurchaseOrderService::class)->generate(
                $order,
                auth()->user(),
                (string) $this->orderWorkflowActionPayload['supplier_po_mode'],
                (array) ($this->orderWorkflowActionPayload['supplier_po_item_ids'] ?? []),
                (array) ($this->orderWorkflowActionPayload['supplier_po_template'] ?? []),
                (array) ($this->orderWorkflowActionPayload['supplier_po_items'] ?? []),
                ($this->orderWorkflowActionPayload['supplier_po_delivery_date'] ?? null) ?: null,
                artworkTaskAction: true,
            );
        } catch (ValidationException $exception) {
            $this->showErrors($exception);
            return;
        }
        $this->create = false;
        $this->prepare($order);
        session()->flash('success', 'Purchase Order PDFs generated. Email them now or finish without sending.');
    }

    public function completeSupplierPurchaseOrdersWithoutEmail(): void
    {
        $order = $this->editableOrder();
        abort_unless(SupplierPurchaseOrder::query()->where('flow_job_id', $order->id)
            ->whereNull('archived_at')->exists(), 422, 'Generate a Purchase Order first.');
        $this->closeOrderWorkflowAction();
        session()->flash('success', 'Purchase Orders saved. You can return to send or download the PDFs later.');
    }

    public function sendSupplierPurchaseOrder(int $poId): void
    {
        $order = $this->editableOrder();
        $po = SupplierPurchaseOrder::query()->where('flow_job_id', $order->id)
            ->whereNull('archived_at')->findOrFail($poId);
        try {
            $updated = app(SupplierPurchaseOrderService::class)->send($order, $po->id, auth()->user(), $this->recipientEmails($poId));
        } catch (ValidationException $exception) {
            $this->showErrors($exception);
            return;
        }
        $this->resetValidation('orderWorkflowActionPayload.supplier_po_emails.'.$poId);
        $this->orderWorkflowActionModalPreview['supplier_po']['rows'] = app(SupplierPurchaseOrderService::class)->rows($order);
        if ($updated->email_status === 'sent') {
            session()->flash('success', $updated->po_number.' sent successfully.');
        } else {
            $this->addError('orderWorkflowActionPayload.supplier_po_emails.'.$poId, 'Sending failed. Check your email provider settings and retry.');
        }
    }

    public function sendAllSupplierPurchaseOrders(): void
    {
        $order = $this->editableOrder();
        $purchaseOrders = SupplierPurchaseOrder::query()->where('flow_job_id', $order->id)
            ->whereNull('archived_at')->whereIn('email_status', ['not_sent', 'failed'])
            ->orderBy('id')->get();
        abort_if($purchaseOrders->isEmpty(), 422, 'All Purchase Orders have already been sent.');
        $service = app(SupplierPurchaseOrderService::class);
        $recipients = [];
        $firstInvalidId = null;
        foreach ($purchaseOrders as $po) {
            $emails = $this->recipientEmails((int) $po->id);
            try {
                $service->validateSend($order, $po, $emails);
                $recipients[$po->id] = $emails;
            } catch (ValidationException $exception) {
                $firstInvalidId ??= (int) $po->id;
                $this->showErrors($exception);
            }
        }
        if ($firstInvalidId !== null) {
            $this->selectSupplierPurchaseOrder($firstInvalidId);
            return;
        }
        $sent = 0;
        $failed = 0;
        $firstFailedId = null;
        foreach ($purchaseOrders as $po) {
            $result = $service->send($order, (int) $po->id, auth()->user(), $recipients[$po->id]);
            if ($result->email_status === 'sent') {
                $sent++;
            } else {
                $failed++;
                $firstFailedId ??= (int) $po->id;
                $this->addError('orderWorkflowActionPayload.supplier_po_emails.'.$po->id, 'Sending failed. Please retry.');
            }
        }
        $this->orderWorkflowActionModalPreview['supplier_po']['rows'] = $service->rows($order);
        if ($firstFailedId !== null) $this->selectSupplierPurchaseOrder($firstFailedId);
        session()->flash($failed ? 'error' : 'success',
            $sent.' Purchase Order(s) emailed'.($failed ? '; '.$failed.' failed and can be retried.' : '.'));
    }

    public function render()
    {
        $user = auth()->user();
        $selectedOrder = $this->orderId ? $this->visibleOrder() : null;
        $orders = null;
        if (!$selectedOrder) {
            // Only query the list while choosing an Order. Editing recipients or
            // switching PDFs must not re-paginate unrelated Orders.
            $query = app(OrderReadService::class)->visibleQuery($user)
                ->where('status', '!=', 'Cancelled');
            $search = trim($this->search);
            if ($search !== '') {
                $query->where(static function ($q) use ($search): void {
                    $term = '%'.$search.'%';
                    $q->where('job_number', 'like', $term)
                        ->orWhere('order_number', 'like', $term)
                        ->orWhere('title', 'like', $term)
                        ->orWhere('product', 'like', $term)
                        ->orWhereHas('client', static fn ($client) => $client
                            ->where('name', 'like', $term)->orWhere('code', 'like', $term))
                        ->orWhereHas('items', static fn ($items) => $items->active()->where('product_name', 'like', $term));
                });
            }
            if ($this->poFilter === 'generated') {
                $query->whereHas('supplierPurchaseOrders', static fn ($po) => $po->whereNull('archived_at'));
            } elseif ($this->poFilter === 'pending') {
                $query->whereDoesntHave('supplierPurchaseOrders', static fn ($po) => $po->whereNull('archived_at'));
            }
            $orders = $query->select([
                    'id', 'job_number', 'order_number', 'client_id', 'title', 'product',
                    'status', 'created_at', 'workflow_phase_id', 'delivery_date',
                    'owner_id', 'coordinator_id', 'created_by',
                ])
                // One indexed scalar lookup identifies the first active product
                // without loading every line item for every Order on the page.
                ->addSelect(['first_po_product' => FlowJobItem::query()
                    ->select('product_name')
                    ->whereColumn('flow_job_id', 'flow_jobs.id')
                    ->where('is_removed', false)
                    ->orderBy('sort_order')->orderBy('id')->limit(1),
                ])
                ->with(['client', 'phase:id,name,color'])
                ->withCount(['items as po_product_count' => static fn ($items) => $items->active()])
                ->withExists([
                    'supplierPurchaseOrders as has_active_supplier_po' => static fn ($po) => $po->whereNull('archived_at'),
                ])
                ->orderByDesc('id')->simplePaginate(15, ['*'], 'poPage');
        }
        return view('livewire.orders.purchase-orders', [
            'orders' => $orders,
            'selectedOrder' => $selectedOrder,
            'canEditSelectedOrder' => $selectedOrder && app(AccessControlService::class)->canEditVisibleJob($user, $selectedOrder),
        ]);
    }
}
