<div class="ft-po-page ft-order-prototype-detail ft-order-list-v5">
    <header class="ft-po-page-header">
        <div>
            <div class="breadcrumbs">Order / Purchase Orders</div>
            <h1>Purchase Orders</h1>
            <p>Generate, review and email supplier purchase orders for existing orders.</p>
        </div>
        <x-ui.button variant="secondary" size="sm" :href="route('jobs.index')" wire:navigate>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            Back to Orders
        </x-ui.button>
    </header>

    @if(session('success'))
        <div class="ft-order-list-flash" role="status">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <p class="validation-error" role="alert">{{ session('error') }}</p>
    @endif

    @if($selectedOrder)
        @php
            $products = (array) data_get($orderWorkflowActionModalPreview, 'supplier_po.products', []);
            $poRows = (array) data_get($orderWorkflowActionModalPreview, 'supplier_po.rows', []);
            $units = array_sum(array_map(static fn ($product) => (int) ($product['quantity'] ?? 0), $products));
            $reference = $selectedOrder->order_number && $selectedOrder->order_number !== $selectedOrder->job_number ? $selectedOrder->order_number : null;
        @endphp
        <section class="ft-po-editor" aria-label="Purchase order generator">
            <header class="ft-po-editor-header">
                <div class="ft-po-editor-identity">
                    <span class="ft-po-eyebrow">ORDER PURCHASE ORDER</span>
                    <h2>{{ $selectedOrder->displayOrderNumber() }}</h2>
                    <p>{{ $selectedOrder->title }}</p>
                </div>
                <div class="ft-po-editor-header-actions">
                    <x-ui.button variant="secondary" size="sm" :href="route('jobs.index', ['open' => $selectedOrder->id])" wire:navigate>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-3"/><path d="M13 3h8v8M21 3l-10 10"/></svg>
                        Open order details
                    </x-ui.button>
                    <x-ui.button variant="secondary" size="sm" wire:click="closeOrderWorkflowAction">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        Back to PO list
                    </x-ui.button>
                </div>
            </header>

            <div class="ft-po-order-summary" aria-label="Selected order details">
                <div class="ft-po-summary-cell ft-po-summary-client">
                    <span class="ft-po-summary-label">CLIENT</span>
                    <div class="ft-po-summary-client-row"><x-ui.client-logo :client="$selectedOrder->client" :size="34" /><strong>{{ $selectedOrder->client?->name ?: 'Unassigned client' }}@if($selectedOrder->client?->code) <small class="ft-po-client-code">{{ $selectedOrder->client->code }}</small>@endif</strong></div>
                </div>
                <div class="ft-po-summary-cell"><span class="ft-po-summary-label">REFERENCE</span><strong>{{ $reference ?: '—' }}</strong></div>
                <div class="ft-po-summary-cell"><span class="ft-po-summary-label">WORKFLOW STAGE</span><strong>{{ $selectedOrder->phase?->name ?: 'Not started' }}</strong></div>
                <div class="ft-po-summary-cell"><span class="ft-po-summary-label" title="Automatically reflects the active order workflow task, not the supplier PO or email status.">ORDER STATUS</span><x-ui.status-badge :label="$selectedOrder->status ?: '—'" :variant="strcasecmp((string) $selectedOrder->status, 'Cancelled') === 0 ? 'danger' : (stripos((string) $selectedOrder->status, 'Waiting') !== false ? 'warning' : 'success')" /><small class="ft-po-summary-status-help">Based on the active workflow task</small></div>
                <div class="ft-po-summary-cell"><span class="ft-po-summary-label">CREATED</span><strong>{{ $selectedOrder->created_at ? \App\Support\UserLocalTime::format($selectedOrder->created_at, 'M j, Y') : '—' }}</strong></div>
                <div class="ft-po-summary-cell"><span class="ft-po-summary-label">DELIVERY DATE</span><strong>{{ $selectedOrder->delivery_date ? \App\Support\UserLocalTime::format($selectedOrder->delivery_date, 'M j, Y') : 'Not set' }}</strong></div>
            </div>

            <div class="ft-po-order-products">
                <div class="ft-po-products-heading">
                    <div><h3>Order products</h3><p>Confirm the products and supplier before generating the PO.</p></div>
                    <span class="ft-po-products-count">{{ count($products) }} {{ count($products) === 1 ? 'product' : 'products' }} <span aria-hidden="true">·</span> {{ number_format($units) }} units</span>
                </div>
                @if($products)
                    <div class="ft-po-products-list" role="list">
                        @foreach(array_slice($products, 0, 3) as $product)
                            <div class="ft-po-product-item" role="listitem" wire:key="ft-po-product-summary-{{ $product['id'] }}">
                                <span class="ft-po-product-index" aria-hidden="true">{{ $loop->iteration }}</span>
                                <span class="ft-po-product-name"><strong title="{{ $product['name'] }}">{{ $product['name'] }}</strong><small>Supplier: {{ $product['supplier'] }}</small></span>
                                <span class="ft-po-product-quantity">{{ number_format((int) $product['quantity']) }} <small>units</small></span>
                            </div>
                        @endforeach
                        @if(count($products) > 3)
                            <details class="ft-po-products-more">
                                <summary>Show {{ count($products) - 3 }} more {{ count($products) - 3 === 1 ? 'product' : 'products' }}</summary>
                                @foreach(array_slice($products, 3) as $product)
                                    <div class="ft-po-product-item" role="listitem" wire:key="ft-po-product-summary-{{ $product['id'] }}">
                                        <span class="ft-po-product-index" aria-hidden="true">{{ $loop->iteration + 3 }}</span>
                                        <span class="ft-po-product-name"><strong title="{{ $product['name'] }}">{{ $product['name'] }}</strong><small>Supplier: {{ $product['supplier'] }}</small></span>
                                        <span class="ft-po-product-quantity">{{ number_format((int) $product['quantity']) }} <small>units</small></span>
                                    </div>
                                @endforeach
                            </details>
                        @endif
                    </div>
                @else
                    <p class="ft-po-no-products">No active products on this order.</p>
                @endif
            </div>

            <div class="ft-po-workspace-heading">
                <div><h3>{{ $poRows ? 'Generated purchase orders' : 'Create supplier purchase order' }}</h3><p>{{ $poRows ? 'Review, download or email the PDFs associated with this order.' : 'Choose a PO format and generate a PDF from the order products above.' }}</p></div>
                @if($poRows)<span class="ft-po-status is-ready">{{ count($poRows) }} {{ count($poRows) === 1 ? 'PO' : 'POs' }} generated</span>@endif
            </div>
            @if(!$canEditSelectedOrder || strcasecmp((string) $selectedOrder->status, 'Cancelled') === 0)
                <p class="ft-po-readonly-note">You can view generated purchase orders for this order, but you don't have permission to create or send them.</p>
                @foreach($poRows as $po)
                    <div class="ft-po-readonly-row">
                        <span><strong>{{ $po['number'] }}</strong><small>{{ $po['supplier'] }} · {{ ucfirst(str_replace('_', ' ', $po['status'])) }}</small></span>
                        @if($po['document_id'])
                            <x-ui.button variant="secondary" size="sm" :href="route('documents.open', $po['document_id'])" target="_blank" rel="noopener">Open PDF</x-ui.button>
                        @endif
                    </div>
                @endforeach
            @else
                <x-jobs.order-detail.supplier-purchase-order-panel
                    :standalone="true"
                    :payload="$orderWorkflowActionPayload"
                    :preview="$orderWorkflowActionModalPreview['supplier_po'] ?? []"
                />
            @endif
        </section>
    @else
        <section class="ft-po-list" aria-label="Orders available for purchase orders">
            <div class="ft-po-list-heading">
                <div><h2>Order purchase orders</h2><p>Find the correct order, then generate or manage its supplier PO.</p></div>
                <span class="ft-po-list-hint">15 orders per page</span>
            </div>
            <div class="ft-po-filter-toolbar">
                <label class="ft-po-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m16 16 4.4 4.4"/></svg>
                    <input type="search" wire:model.live.debounce.600ms="search" placeholder="Search order, reference, client or product" aria-label="Search orders" autocomplete="off" />
                </label>
                <div class="ft-po-filter-controls" role="group" aria-label="Filter by purchase order status">
                    <button type="button" @class(['ft-po-filter-chip', 'is-active' => $poFilter === 'all']) wire:click="setPoFilter('all')" aria-pressed="{{ $poFilter === 'all' ? 'true' : 'false' }}">All orders</button>
                    <button type="button" @class(['ft-po-filter-chip', 'is-active' => $poFilter === 'pending']) wire:click="setPoFilter('pending')" aria-pressed="{{ $poFilter === 'pending' ? 'true' : 'false' }}">To generate</button>
                    <button type="button" @class(['ft-po-filter-chip', 'is-active' => $poFilter === 'generated']) wire:click="setPoFilter('generated')" aria-pressed="{{ $poFilter === 'generated' ? 'true' : 'false' }}">Generated</button>
                </div>
                @if(filled($search) || $poFilter !== 'all')
                    <button type="button" class="ft-po-clear" wire:click="clearPoFilters">Clear filters</button>
                @endif
            </div>
            <div class="ft-po-table-summary">
                <span><strong>{{ $orders->count() }}</strong> {{ $orders->count() === 1 ? 'order' : 'orders' }} on this page</span>
                <span>{{ $poFilter === 'pending' ? 'Awaiting PO generation' : ($poFilter === 'generated' ? 'Generated supplier POs' : 'All PO statuses') }}</span>
            </div>
            <div class="ft-po-table-scroll" wire:loading.class="is-loading" wire:target="search,setPoFilter,gotoPage,previousPage,nextPage">
                <table class="ft-po-table">
                    <thead><tr><th>Order</th><th>Client &amp; product</th><th>Current stage</th><th>Order status</th><th>PO status</th><th>Action</th></tr></thead>
                    <tbody>
                        @forelse($orders as $order)
                            @php
                                $reference = $order->order_number && $order->order_number !== $order->job_number ? $order->order_number : null;
                                $productName = $order->first_po_product ?: $order->product ?: $order->title;
                            @endphp
                            <tr @class(['ft-po-order-row', 'has-po' => $order->has_active_supplier_po]) wire:key="purchase-order-list-{{ $order->id }}">
                                <td data-label="Order" class="ft-po-order-identity">
                                    <a class="ft-po-order-id" href="{{ route('jobs.index', ['open' => $order->id]) }}" wire:navigate>{{ $order->displayOrderNumber() }}</a>
                                    <small>{{ $reference ?: 'No reference' }} <span aria-hidden="true">·</span> {{ $order->created_at ? \App\Support\UserLocalTime::format($order->created_at, 'M j, Y') : '—' }}</small>
                                </td>
                                <td data-label="Client &amp; product" class="ft-po-client-product">
                                    <div class="ft-po-client-product-row">
                                        <x-ui.client-logo :client="$order->client" :size="34" />
                                        <span><strong title="{{ $order->client?->name ?: 'Client' }}">{{ $order->client?->name ?: 'Client' }}</strong><small title="{{ $productName }}">{{ $productName ?: 'No product' }}@if($order->po_product_count > 1) <span class="ft-po-more-count">+{{ $order->po_product_count - 1 }} more</span>@endif</small></span>
                                    </div>
                                </td>
                                <td data-label="Current stage"><span class="ft-po-stage-chip" @if($order->phase?->color) style="--po-stage-color: {{ \App\Support\MasterColor::normalize($order->phase->color) ?: '#64748b' }}" @endif>{{ $order->phase?->name ?: 'Not started' }}</span></td>
                                <td data-label="Order status"><x-ui.status-badge :label="$order->status ?: '—'" :variant="stripos((string) $order->status, 'Waiting') !== false ? 'warning' : 'success'" /></td>
                                <td data-label="PO status"><span @class(['ft-po-status', 'is-ready' => $order->has_active_supplier_po])><span class="ft-po-status-dot" aria-hidden="true"></span>{{ $order->has_active_supplier_po ? 'Generated' : 'Not generated' }}</span></td>
                                <td data-label="Action"><x-orders.po-action :order-id="$order->id" :generated="(bool) $order->has_active_supplier_po" :select="true" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="ft-po-empty"><strong>No orders found</strong><span>Try another search or choose a different PO status.</span></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="ft-po-pagination">{{ $orders->links() }}</div>
        </section>
    @endif
</div>
