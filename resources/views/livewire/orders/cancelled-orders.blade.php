<div class="ft-cancelled-orders-page">
    <div class="ft-cancelled-page-head">
        <div>
            <div class="ft-cancelled-breadcrumb">Order / <span>Cancelled Orders</span></div>
            <h1>Cancelled Orders</h1>
            <p>Review orders that were cancelled, why they were cancelled, and who cancelled them.</p>
        </div>

        <div class="ft-cancelled-head-actions">
            <a href="{{ route('jobs.index') }}" wire:navigate class="ft-cancelled-btn">
                <span aria-hidden="true">←</span>
                <span>Back to active orders</span>
            </a>

            @if($canExport)
                <a href="{{ route('orders.cancelled.export', $exportQuery) }}" class="ft-cancelled-btn">
                    Export cancelled orders
                </a>
            @else
                <button type="button" class="ft-cancelled-btn" disabled>Export cancelled orders</button>
            @endif
        </div>
    </div>

    <div class="ft-cancelled-history-notice" role="note">
        <span class="ft-cancelled-history-icon" aria-hidden="true">i</span>
        <p><strong>Cancelled orders are kept as historical records.</strong> They are excluded from active workflow-stage totals and normal production progress.</p>
    </div>

    <section class="ft-cancelled-metrics" aria-label="Cancelled order summary" wire:loading.class="is-loading">
        <article class="ft-cancelled-metric-card is-red">
            <span class="ft-cancelled-metric-label">TOTAL CANCELLED</span>
            <strong>{{ number_format((int) $metrics['total']) }}</strong>
            <small>Matching the current view</small>
        </article>

        <article class="ft-cancelled-metric-card is-blue">
            <span class="ft-cancelled-metric-label">CANCELLED THIS MONTH</span>
            <strong>{{ number_format((int) $metrics['this_month']) }}</strong>
            <small>{{ $metrics['month_label'] }}</small>
        </article>

        <article class="ft-cancelled-metric-card is-purple">
            <span class="ft-cancelled-metric-label">{{ \Illuminate\Support\Str::upper((string) $metrics['common_reason_label']) }}</span>
            <strong>{{ number_format((int) $metrics['common_reason_count']) }}</strong>
            <small>Most common reason</small>
        </article>

        <article class="ft-cancelled-metric-card is-green">
            <span class="ft-cancelled-metric-label">RESTORABLE</span>
            <strong>{{ number_format((int) $metrics['restorable']) }}</strong>
            <small>Can return to active workflow</small>
        </article>
    </section>

    <section class="ft-cancelled-history-card">
        <header class="ft-cancelled-history-head">
            <div>
                <h2>Cancelled order history</h2>
                <p>Search and review cancellation details without mixing them with active orders.</p>
            </div>
            <span class="ft-cancelled-history-only">Historical records only</span>
        </header>

        <div class="ft-cancelled-filters">
            <label class="ft-cancelled-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"></circle><path d="m16 16 4 4"></path></svg>
                <input
                    type="search"
                    wire:model.live.debounce.500ms="search"
                    placeholder="Search order, reference, client or product"
                    autocomplete="off"
                    aria-label="Search cancelled orders"
                >
            </label>

            <select wire:model.live="clientId" aria-label="Filter cancelled orders by client">
                <option value="">All clients</option>
                @foreach($clientOptions as $client)
                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="phaseId" aria-label="Filter cancelled orders by last stage">
                <option value="">All last stages</option>
                @foreach($phaseOptions as $phase)
                    <option value="{{ $phase->id }}">{{ $phase->short_name ?: $phase->name }}</option>
                @endforeach
            </select>

            <select wire:model.live="reason" aria-label="Filter cancelled orders by reason">
                <option value="">All reasons</option>
                @foreach($reasonOptions as $reasonKey => $reasonLabel)
                    <option value="{{ $reasonKey }}">{{ $reasonLabel }}</option>
                @endforeach
            </select>

            <select wire:model.live="cancelledBy" aria-label="Filter cancelled orders by user">
                <option value="">Cancelled by anyone</option>
                @foreach($cancellerOptions as $person)
                    <option value="{{ $person->id }}">{{ $person->name }}</option>
                @endforeach
            </select>

            <label class="ft-cancelled-date-field" title="Cancelled from">
                <span>From</span>
                <input type="date" wire:model.live="fromDate" aria-label="Cancelled from date">
            </label>

            <label class="ft-cancelled-date-field" title="Cancelled to">
                <span>To</span>
                <input type="date" wire:model.live="toDate" aria-label="Cancelled to date">
            </label>

            <button type="button" class="ft-cancelled-clear" wire:click="clearFilters">Clear</button>
        </div>

        <div class="ft-cancelled-list-meta">
            <span>{{ number_format($orders->total()) }} cancelled {{ \Illuminate\Support\Str::plural('order', $orders->total()) }}</span>
            <span>Sorted by latest cancellation</span>
        </div>

        <div class="ft-cancelled-table-wrap" role="region" aria-label="Cancelled orders table" tabindex="0">
            <table class="ft-cancelled-table">
                <colgroup>
                    <col class="ft-co-col-order">
                    <col class="ft-co-col-client">
                    <col class="ft-co-col-stage">
                    <col class="ft-co-col-status">
                    <col class="ft-co-col-reason">
                    <col class="ft-co-col-cancelled">
                    <col class="ft-co-col-owner">
                    <col class="ft-co-col-action">
                </colgroup>
                <thead>
                    <tr>
                        <th>ORDER</th>
                        <th>CLIENT &amp; PRODUCT</th>
                        <th>LAST STAGE</th>
                        <th>STATUS</th>
                        <th>CANCELLATION REASON</th>
                        <th>CANCELLED BY / DATE</th>
                        <th>ORDER OWNER</th>
                        <th>ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr wire:key="cancelled-order-row-{{ $row['id'] }}">
                            <td class="ft-cancelled-order-cell">
                                <a href="{{ $row['open_url'] }}" wire:navigate>{{ $row['order_number'] }}</a>
                                <small title="{{ $row['reference'] }} · {{ $row['created_date'] }}">{{ $row['reference'] }} · {{ $row['created_date'] }}</small>
                            </td>

                            <td>
                                <div class="ft-cancelled-client-product">
                                    <span class="ft-cancelled-client-logo" aria-hidden="true">{{ $row['client_initial'] }}</span>
                                    <span class="ft-cancelled-client-product-copy">
                                        <strong title="{{ $row['client_name'] }}">{{ $row['client_name'] }}</strong>
                                        <small title="{{ $row['product_name'] }} · {{ number_format((int) $row['quantity']) }} pcs">{{ $row['product_name'] }} · {{ number_format((int) $row['quantity']) }} pcs</small>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <span class="ft-cancelled-stage-pill stage-{{ max(1, min(7, (int) $row['stage_sequence'])) }}" title="{{ $row['stage_name'] }}">
                                    <i aria-hidden="true"></i>
                                    {{ $row['stage_name'] }}
                                </span>
                            </td>

                            <td>
                                <span class="ft-cancelled-status-pill">Cancelled</span>
                            </td>

                            <td class="ft-cancelled-reason-cell">
                                <strong>{{ $row['reason_label'] }}</strong>
                                <small title="{{ $row['reason_detail'] ?: 'No text reason was recorded. Open the order to review cancellation attachments.' }}">
                                    {{ $row['reason_detail'] ?: 'No text reason was recorded. Open the order to review cancellation attachments.' }}
                                </small>
                            </td>

                            <td>
                                <div class="ft-cancelled-person">
                                    <x-ui.avatar
                                        :name="$row['cancelled_by_name']"
                                        :src="$row['cancelled_by_image_url']"
                                        :size="30"
                                        class="ft-cancelled-person-avatar"
                                    />
                                    <span class="ft-cancelled-person-copy">
                                        <strong title="{{ $row['cancelled_by_name'] }}">{{ $row['cancelled_by_name'] }}</strong>
                                        <small>{{ $row['cancelled_at_date'] }} · {{ $row['cancelled_at_time'] }}</small>
                                    </span>
                                </div>
                            </td>

                            <td>
                                <div class="ft-cancelled-person">
                                    <x-ui.avatar
                                        :name="$row['owner_name']"
                                        :src="$row['owner_image_url']"
                                        :size="30"
                                        class="ft-cancelled-person-avatar"
                                    />
                                    <span class="ft-cancelled-person-copy">
                                        <strong title="{{ $row['owner_name'] }}">{{ $row['owner_name'] }}</strong>
                                        <small>Order owner</small>
                                    </span>
                                </div>
                            </td>

                            <td class="ft-cancelled-action-cell">
                                <div
                                    class="ft-cancelled-row-menu"
                                    x-data="{
                                        open: false,
                                        top: 0,
                                        left: 0,
                                        toggle() {
                                            if (this.open) {
                                                this.open = false;
                                                return;
                                            }

                                            const trigger = this.$refs.trigger;
                                            if (!trigger) return;

                                            const rect = trigger.getBoundingClientRect();
                                            const menuWidth = 148;
                                            const menuHeight = 44;
                                            const gap = 6;
                                            const edge = 12;

                                            this.left = Math.max(
                                                edge,
                                                Math.min(window.innerWidth - menuWidth - edge, rect.right - menuWidth)
                                            );

                                            this.top = window.innerHeight - rect.bottom >= menuHeight + gap + edge
                                                ? rect.bottom + gap
                                                : Math.max(edge, rect.top - menuHeight - gap);

                                            this.open = true;
                                        }
                                    }"
                                    x-on:keydown.escape.window="open = false"
                                    x-on:resize.window="open = false"
                                    x-on:scroll.window="open = false"
                                >
                                    <button
                                        x-ref="trigger"
                                        type="button"
                                        class="ft-cancelled-row-menu-trigger"
                                        aria-label="Cancelled order actions"
                                        aria-haspopup="menu"
                                        x-bind:aria-expanded="open ? 'true' : 'false'"
                                        x-on:click.stop="toggle()"
                                    >
                                        <span aria-hidden="true">•••</span>
                                    </button>

                                    <template x-teleport="body">
                                        <div
                                            x-cloak
                                            x-show="open"
                                            x-transition.opacity.duration.120ms
                                            class="ft-cancelled-row-menu-popover"
                                            role="menu"
                                            x-bind:style="`top: ${top}px; left: ${left}px;`"
                                            x-on:click.outside="open = false"
                                        >
                                            <a href="{{ $row['open_url'] }}" wire:navigate role="menuitem" x-on:click="open = false">
                                                Open order
                                            </a>
                                        </div>
                                    </template>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="ft-cancelled-empty">No cancelled orders match the selected filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="ft-cancelled-footer">
            <span>
                Showing {{ $orders->total() ? $orders->firstItem() : 0 }}–{{ $orders->total() ? $orders->lastItem() : 0 }} of {{ number_format($orders->total()) }} cancelled orders
            </span>

            @if($orders->lastPage() > 1)
                @php
                    $current = $orders->currentPage();
                    $last = $orders->lastPage();
                    $start = max(1, $current - 1);
                    $end = min($last, $current + 1);
                @endphp
                <div class="ft-cancelled-pagination" aria-label="Cancelled order pagination">
                    <button type="button" wire:click="goToCancelledPage({{ max(1, $current - 1) }})" @disabled($current <= 1)>←</button>
                    @for($page = $start; $page <= $end; $page++)
                        <button type="button" class="{{ $page === $current ? 'active' : '' }}" wire:click="goToCancelledPage({{ $page }})">{{ $page }}</button>
                    @endfor
                    <button type="button" wire:click="goToCancelledPage({{ min($last, $current + 1) }})" @disabled($current >= $last)>→</button>
                </div>
            @endif
        </footer>
    </section>
</div>
