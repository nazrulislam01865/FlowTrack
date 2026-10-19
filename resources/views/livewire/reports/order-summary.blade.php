<div class="ft-order-summary-report">
    <div class="ft-osr-breadcrumb">Reports / <b>Order Summary</b></div>

    <div class="ft-osr-title-row">
        <div>
            <h1>Order Summary Report</h1>
            <div class="ft-osr-subtitle">Supplier, material, sample and delivery tracking in one operational report.</div>
        </div>

        <div class="ft-osr-actions">
            <button type="button" wire:click="resetFilters">Reset</button>

            @if($canExport)
                <a
                    class="ft-osr-btn ft-osr-btn-primary"
                    href="{{ route('order-summary.export', $exportQuery) }}"
                >⇩ Download Excel</a>
            @else
                <button type="button" class="ft-osr-btn ft-osr-btn-primary" disabled>⇩ Download Excel</button>
            @endif
        </div>
    </div>

    <section class="ft-osr-panel">
        <div class="ft-osr-filters">
            <div class="ft-osr-field">
                <label>SEARCH</label>
                <input
                    type="search"
                    wire:model.live.debounce.500ms="search"
                    placeholder="Order no., supplier, material..."
                    autocomplete="off"
                >
            </div>

            <div class="ft-osr-field">
                <label>SUPPLIER</label>
                <select wire:model.live="supplierId">
                    <option value="">All suppliers</option>
                    @foreach($supplierOptions as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ft-osr-field">
                <label>WAREHOUSE</label>
                <select wire:model.live="warehouse">
                    <option value="">All warehouses</option>
                    @foreach($warehouseOptions as $warehouseOption)
                        <option value="{{ $warehouseOption }}">{{ $warehouseOption }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ft-osr-field">
                <label>URGENCY</label>
                <select wire:model.live="urgency">
                    <option value="">All</option>
                    <option value="Y">Urgent</option>
                    <option value="N">Normal</option>
                </select>
            </div>

            <div class="ft-osr-field">
                <label>RECEIVED FROM</label>
                <input type="date" wire:model.live="fromDate">
            </div>

            <div class="ft-osr-field">
                <label>RECEIVED TO</label>
                <input type="date" wire:model.live="toDate">
            </div>

            <div class="ft-osr-filter-actions">
                <button type="button" wire:click="applyFilters">Apply</button>
            </div>
        </div>

        <x-reports.client-checkbox-filter
            :clients="$clientOptions"
            :selected-ids="$clientIds"
        />

        <div class="ft-osr-quickbar">
            <div class="ft-osr-chips">
                <button type="button" class="ft-osr-chip {{ $quick === 'all' ? 'active' : '' }}" wire:click="setQuick('all')">
                    All <b>{{ number_format($counts['all']) }}</b>
                </button>
                <button type="button" class="ft-osr-chip {{ $quick === 'urgent' ? 'active' : '' }}" wire:click="setQuick('urgent')">
                    Urgent <b>{{ number_format($counts['urgent']) }}</b>
                </button>
                <button type="button" class="ft-osr-chip {{ $quick === 'awaiting' ? 'active' : '' }}" wire:click="setQuick('awaiting')">
                    Awaiting supplier reply <b>{{ number_format($counts['awaiting']) }}</b>
                </button>
                <button type="button" class="ft-osr-chip {{ $quick === 'overdue' ? 'active' : '' }}" wire:click="setQuick('overdue')">
                    Overdue <b>{{ number_format($counts['overdue']) }}</b>
                </button>
            </div>

            <div class="ft-osr-legend">
                <span class="ft-osr-legend-item"><span class="ft-osr-dot red"></span>Overdue</span>
                <span class="ft-osr-legend-item"><span class="ft-osr-dot orange"></span>Urgent</span>
                <span class="ft-osr-legend-item"><span class="ft-osr-dot green"></span>Completed/on track</span>
                <span class="ft-osr-legend-item"><span class="ft-osr-dot yellow"></span>Supplier reply</span>
            </div>
        </div>
    </section>

    <section class="ft-osr-table-card">
        <div class="ft-osr-table-head">
            <div class="ft-osr-table-title">Order summary</div>
            <div class="ft-osr-table-meta">{{ number_format($orders->total()) }} records · Horizontal scroll available</div>
        </div>

        <div class="ft-osr-table-wrap">
            <table class="ft-osr-table">
                <colgroup>
                    <col class="ft-osr-col-supplier">
                    <col class="ft-osr-col-warehouse">
                    <col class="ft-osr-col-order">
                    <col class="ft-osr-col-received">
                    <col class="ft-osr-col-urgency">
                    <col class="ft-osr-col-quantity">
                    <col class="ft-osr-col-material">
                    <col class="ft-osr-col-erp">
                    <col class="ft-osr-col-special">
                    <col class="ft-osr-col-sample-sent">
                    <col class="ft-osr-col-sample-confirmed">
                    <col class="ft-osr-col-revise">
                    <col class="ft-osr-col-delivery">
                    <col class="ft-osr-col-reply">
                </colgroup>
                <thead>
                <tr>
                    <th class="sticky1">Supplier</th>
                    <th class="sticky2">Warehouse</th>
                    <th>Order No.</th>
                    <th>Received Date</th>
                    <th>Urgent or Not</th>
                    <th>Quantity</th>
                    <th>Material</th>
                    <th>Artwork Approval Date</th>
                    <th>Special Orders</th>
                    <th>Sample/Swatch Sent Date</th>
                    <th>Sample/Swatch Confirmed Date</th>
                    <th>Revise / Sample Confirm Date</th>
                    <th>Supplier Delivery Date<br><span class="muted">供应商到货日期</span></th>
                    <th>Supplier Reply<br><span class="muted">供应商回复交期</span></th>
                </tr>
                </thead>
                <tbody>
                @forelse($rows as $row)
                    <tr wire:key="order-summary-row-{{ $row['id'] }}" class="row-{{ $row['state'] }}">
                        <td class="sticky1 supplier-cell">{{ $row['supplier'] }}</td>
                        <td class="sticky2">{{ $row['warehouse'] }}</td>
                        <td class="order-no ft-osr-nowrap">{{ $row['order'] }}</td>
                        <td class="ft-osr-nowrap">{{ $row['received'] ?: '—' }}</td>
                        <td>
                            @if($row['urgent'] === 'Y')
                                <span class="ft-osr-badge ft-osr-badge-danger">Urgent</span>
                            @else
                                <span class="ft-osr-badge ft-osr-badge-neutral">Normal</span>
                            @endif
                        </td>
                        <td class="center ft-osr-nowrap">{{ number_format((int) $row['quantity']) }}</td>
                        <td class="ft-osr-wrap">{{ $row['material'] }}</td>
                        <td class="ft-osr-nowrap">{{ $row['artwork_approval'] ?: '—' }}</td>
                        <td class="special">{{ $row['special_orders'] ?: '—' }}</td>
                        <td class="ft-osr-nowrap">{{ $row['sample_sent'] ?: '—' }}</td>
                        <td class="ft-osr-nowrap">{{ $row['sample_confirmed'] ?: '—' }}</td>
                        <td class="ft-osr-nowrap">{{ $row['revise_confirm'] ?: '—' }}</td>
                        <td class="ft-osr-nowrap">{{ $row['supplier_delivery'] ?: '—' }}</td>
                        <td class="{{ $row['supplier_reply'] !== '' ? 'reply-cell' : '' }}">
                            @if($row['supplier_reply'] !== '')
                                {{ $row['supplier_reply'] }}
                            @else
                                <span class="ft-osr-badge ft-osr-badge-warning">Awaiting reply</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="14" class="ft-osr-empty">No Orders match the selected report filters.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="ft-osr-footer">
            <div>
                Showing <b>{{ $orders->total() ? $orders->firstItem() : 0 }}–{{ $orders->total() ? $orders->lastItem() : 0 }}</b>
                of <b>{{ number_format($orders->total()) }}</b> records
            </div>

            @if($orders->lastPage() > 1)
                @php
                    $current = $orders->currentPage();
                    $last = $orders->lastPage();
                    $start = max(1, $current - 1);
                    $end = min($last, $current + 1);
                @endphp
                <div class="ft-osr-pages">
                    @if($current > 1)
                        <button type="button" class="ft-osr-page" wire:click="goToReportPage({{ $current - 1 }})">‹</button>
                    @endif
                    @for($page = $start; $page <= $end; $page++)
                        <button type="button" class="ft-osr-page {{ $page === $current ? 'active' : '' }}" wire:click="goToReportPage({{ $page }})">{{ $page }}</button>
                    @endfor
                    @if($current < $last)
                        <button type="button" class="ft-osr-page" wire:click="goToReportPage({{ $current + 1 }})">›</button>
                    @endif
                </div>
            @endif
        </div>
    </section>
</div>
