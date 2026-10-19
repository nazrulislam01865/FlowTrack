@props([
    'jobs',
    'searchFilter' => '',
    'clientFilter' => '',
    'phaseFilter' => '',
    'assigneeFilter' => '',
    'ownerFilter' => null,
    'metrics' => [],
    'metricFilter' => '',
    'dateFrom' => '',
    'dateTo' => '',
    'importFilterId' => 0,
    'importFilterLabel' => '',
    'dateRangeEnabled' => false,
    'clientFilterOptions' => collect(),
    'phaseFilterOptions' => collect(),
    'assigneeFilterOptions' => collect(),
    'ownerFilterOptions' => collect(),
    'clearAction' => 'clearSearch',
    'clearFiltersAction' => null,
    'selectedOrderIds' => [],
    'showBulkDeleteConfirm' => false,
])
@php
    $masterData = app(\App\Services\MasterDataService::class);
    $tone = static function (?string $value): string {
        $value = (string) $value;
        if (preg_match('/delayed|issue|overdue|blocked|attention|critical/i', $value)) return 'red';
        if (preg_match('/risk|wait|reply|hold|revision|delay|due|urgent|high/i', $value)) return 'amber';
        if (preg_match('/track|ready|invoice|warehouse|shipping|completed/i', $value)) return 'green';
        if (preg_match('/artwork|sample|client/i', $value)) return 'purple';
        return 'blue';
    };
    $currentPage = $jobs->currentPage();
    $lastPage = $jobs->lastPage();
    $pageNumbers = collect(range(1, max(1, $lastPage)))
        ->filter(fn ($page) => $lastPage <= 7 || $page === 1 || $page === $lastPage || abs($page - $currentPage) <= 1)
        ->values();
    $usesOwnerFilter = $ownerFilter !== null;
    $peopleFilter = $usesOwnerFilter ? (string) $ownerFilter : (string) $assigneeFilter;
    $peopleFilterOptions = $usesOwnerFilter ? $ownerFilterOptions : $assigneeFilterOptions;
    $peopleProperty = $usesOwnerFilter ? 'owner' : 'assignee';
    $peopleContext = $usesOwnerFilter ? 'order-list-owner' : 'order-list';
    $peopleLabel = $usesOwnerFilter ? 'Owner' : 'Assignee';
    $peoplePlaceholder = $usesOwnerFilter ? 'All owner' : 'All assignees';
    $accessControl = app(\App\Services\AccessControlService::class);
    $canViewFinance = $accessControl->can(auth()->user(), 'finance', 'view');
    $canDeleteOrders = auth()->user()->canModule('jobs', 'delete');
    $orderExportQuery = array_filter([
        'search' => filled($searchFilter) ? $searchFilter : null,
        'client' => filled($clientFilter) ? $clientFilter : null,
        'phase' => filled($phaseFilter) ? $phaseFilter : null,
        'owner' => $usesOwnerFilter && filled($peopleFilter) ? $peopleFilter : null,
        'assignee' => ! $usesOwnerFilter && filled($peopleFilter) ? $peopleFilter : null,
        'metric' => filled($metricFilter) ? $metricFilter : null,
        'date_from' => filled($dateFrom) ? $dateFrom : null,
        'date_to' => filled($dateTo) ? $dateTo : null,
        'import' => $importFilterId ? $importFilterId : null,
    ], static fn ($value) => $value !== null && $value !== '');
    $selectedOrderIdSet = collect($selectedOrderIds)->map(fn ($id) => (int) $id)->filter()->unique();
    $visibleOrderIds = collect($jobs->items())->pluck('id')->map(fn ($id) => (int) $id)->values();
    $selectedOrderCount = $selectedOrderIdSet->count();
    $selectedVisibleCount = $visibleOrderIds->filter(fn ($id) => $selectedOrderIdSet->contains($id))->count();
    $allVisibleOrdersSelected = $visibleOrderIds->isNotEmpty() && $selectedVisibleCount === $visibleOrderIds->count();
    $someVisibleOrdersSelected = $selectedVisibleCount > 0 && ! $allVisibleOrdersSelected;
@endphp

<div id="ft-orders-page" class="ft-orders-prototype">
@if(session('success'))<div class="ft-list-flash" role="status">{{ session('success') }}</div>@endif

    <div class="ft-page-head">
        <div><h1>Orders</h1><p>Fast access to every active and completed order</p></div>
        <div class="ft-actions">
            @if(auth()->user()->canModule('reports', 'export'))
                <x-ui.list-export-period-modal
                    :action="route('orders.export')"
                    :filters="$orderExportQuery"
                    button-class="ft-button ft-list-export-button"
                    entity-label="orders"
                />
            @endif
            @if(auth()->user()->canAccess('jobs.create'))
                <a class="ft-button ft-bulk-import-button" href="{{ route('orders.bulk-import') }}">⇧ Bulk Import</a>
            @endif
            @if(auth()->user()->canModule('jobs', 'create'))
                <a class="ft-new-job-btn ft-dashboard-action-match" href="{{ route('jobs.index', ['create' => 1]) }}" wire:navigate><span class="ft-dashboard-action-match-icon">+</span>New Order</a>
            @endif
        </div>
    </div>

    @if(! empty($metrics))
        <div class="metrics ft-summary-card-grid ft-order-summary" aria-label="Order summary filters">
            <x-ui.summary-card label="Created Today" :value="$metrics['createdToday'] ?? 0" icon="created" tone="blue" caption="New orders received" :active="$metricFilter === 'createdToday'" wire:click="setMetricFilter('createdToday')" aria-pressed="{{ $metricFilter === 'createdToday' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="Not Started" :value="$metrics['notStarted'] ?? 0" icon="not-started" tone="slate" caption="Waiting for first action" :active="$metricFilter === 'notStarted'" wire:click="setMetricFilter('notStarted')" aria-pressed="{{ $metricFilter === 'notStarted' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="In Progress" :value="$metrics['inProgress'] ?? 0" icon="in-progress" tone="blue" caption="Work currently underway" :active="$metricFilter === 'inProgress'" wire:click="setMetricFilter('inProgress')" aria-pressed="{{ $metricFilter === 'inProgress' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="Due This Week" :value="$metrics['dueThisWeek'] ?? 0" icon="due-week" tone="amber" caption="Required date this week" :active="$metricFilter === 'dueThisWeek'" wire:click="setMetricFilter('dueThisWeek')" aria-pressed="{{ $metricFilter === 'dueThisWeek' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="Completed This Week" :value="$metrics['completedThisWeek'] ?? 0" icon="completed" tone="green" caption="Finished this week" :active="$metricFilter === 'completedThisWeek'" wire:click="setMetricFilter('completedThisWeek')" aria-pressed="{{ $metricFilter === 'completedThisWeek' ? 'true' : 'false' }}" />
            <x-ui.summary-card label="Needs Attention" :value="$metrics['attention'] ?? 0" icon="attention" tone="red" caption="Blocked, overdue or unassigned" :active="$metricFilter === 'attention'" wire:click="setMetricFilter('attention')" aria-pressed="{{ $metricFilter === 'attention' ? 'true' : 'false' }}" />
        </div>
    @endif

    <section class="ft-list-shell" aria-label="Orders list">
        <x-ui.filter-bar class="ft-search-bar" label="Order filters">
            <label class="ft-search">
                <span class="ft-search-icon">⌕</span>
                <input
                    type="search"
                    autocomplete="off"
                    placeholder="Search order, inquiry, client, product, creator or owner"
                    aria-label="Search orders"
                    wire:model.live.debounce.700ms="search"
                >
                <button @class(['ft-search-clear','show'=>filled($searchFilter)]) wire:click="{{ $clearAction }}" type="button">Clear</button>
            </label>

            <div class="ft-order-filter-controls">
                <x-ui.search-select
                    class="ft-order-list-filter ft-order-list-filter-client"
                    label="Client"
                    property="client"
                    type="clients"
                    context="jobs"
                    :value="$clientFilter"
                    placeholder="All clients"
                    :initial-options="$clientFilterOptions"
                    :fixed-menu="true"
                    :menu-width="280"
                    wire:key="order-list-client-filter-{{ filled($clientFilter) ? $clientFilter : 'all' }}"
                />

                <x-ui.search-select
                    class="ft-order-list-filter ft-order-list-filter-phase"
                    label="Phase"
                    property="phase"
                    type="phases"
                    context="order-list"
                    :value="$phaseFilter"
                    placeholder="All phases"
                    :initial-options="$phaseFilterOptions"
                    :fixed-menu="true"
                    :menu-width="280"
                    wire:key="order-list-phase-filter-{{ filled($phaseFilter) ? $phaseFilter : 'all' }}"
                />

                <x-ui.search-select
                    class="ft-order-list-filter ft-order-list-filter-owner"
                    :label="$peopleLabel"
                    :property="$peopleProperty"
                    type="users"
                    :context="$peopleContext"
                    :value="$peopleFilter"
                    :placeholder="$peoplePlaceholder"
                    :initial-options="$peopleFilterOptions"
                    :fixed-menu="true"
                    :menu-width="260"
                    wire:key="order-list-people-filter-{{ $peopleProperty }}-{{ filled($peopleFilter) ? $peopleFilter : 'all' }}"
                />

                @if($dateRangeEnabled)
                    <x-ui.date-range
                        class="ft-order-date-range"
                        from-property="dateFrom"
                        to-property="dateTo"
                        :from-value="$dateFrom"
                        :to-value="$dateTo"
                        label="Created date"
                        from-label="From"
                        to-label="To"
                    />
                @endif

                @if($clearFiltersAction)
                    <x-ui.filter-reset
                        class="ft-order-clear-filters"
                        :action="$clearFiltersAction"
                        label="Clear filters"
                        :disabled="blank($searchFilter) && blank($clientFilter) && blank($phaseFilter) && blank($peopleFilter) && blank($metricFilter) && blank($dateFrom) && blank($dateTo) && ! $importFilterId"
                    />
                @endif
            </div>
        </x-ui.filter-bar>

        @if($importFilterId)
            <div class="ft-import-batch-filter" role="status" aria-live="polite">
                <span class="ft-import-batch-filter-dot" aria-hidden="true"></span>
                <span>Showing <strong>{{ number_format($jobs->total()) }}</strong> {{ \Illuminate\Support\Str::plural('order', $jobs->total()) }} imported in <strong>{{ $importFilterLabel ?: 'this import' }}</strong></span>
                @if($clearFiltersAction)
                    <button type="button" wire:click="{{ $clearFiltersAction }}">Show all orders</button>
                @endif
            </div>
        @endif

        @if($canDeleteOrders && $selectedOrderCount > 0)
            <x-jobs.bulk-delete-bar :count="$selectedOrderCount" />
        @endif

        @if($canDeleteOrders && $showBulkDeleteConfirm && $selectedOrderCount > 0)
            <x-jobs.bulk-delete-confirmation :count="$selectedOrderCount" />
        @endif

        <div class="ft-order-table-scroll" tabindex="0" aria-label="Orders table. Scroll horizontally to view all columns when needed.">
            <div class="ft-job-head">
                <span>
                    @if($canDeleteOrders)
                        <span class="ft-order-select-wrap">
                            <input
                                class="ft-order-select"
                                type="checkbox"
                                aria-label="Select all orders on this page"
                                @checked($allVisibleOrdersSelected)
                                x-on:change="$wire.toggleOrderPageSelection(@js($visibleOrderIds->all()), $event.target.checked)"
                            >
                            <span>Created by / on</span>
                        </span>
                    @else
                        <span>Created by / on</span>
                    @endif
                </span><span>Order</span><span>Inquiry</span><span>Client / Products</span><span>Phase</span><span>Flag</span><span>Owner / Delivery</span><span>Progress</span><span aria-label="Actions"></span>
            </div>

            <div class="ft-job-list">
            @forelse($jobs as $job)
                @php
                    $creator = $job->createdActivity?->user ?? $job->owner;
                    $creatorName = $creator?->name ?? 'System';
                    $ownerName = $job->owner?->name ?? 'Unassigned';
                    $ownerInitials = collect(preg_split('/\\s+/', trim($ownerName)))->filter()->map(fn($part)=>mb_substr($part,0,1))->take(2)->implode('');
                    $ownerImage = $job->owner?->profileImageUrl();
                    $items = $job->items;
                    $productRows = $items->isNotEmpty()
                        ? $items
                        : collect([(object)['product_name'=>$job->product ?: 'Product','quantity'=>(int)$job->quantity]]);
                    $totalUnits = (int) $productRows->sum(fn($item)=>(int)($item->quantity ?? 0));
                    $productNames = $productRows->pluck('product_name')->filter()->values();
                    $phaseName = $job->phase?->name ?? $job->status ?? '—';
                    // The automatic Order Flag remains stored independently in
                    // flow_jobs.order_flag_id. A manual attention request is also stored
                    // independently and only takes display precedence in the list.
                    $automaticFlag = app(\App\Services\OrderTaskFlagService::class)->labelForOrder($job);
                    $manualAttention = (bool) ($job->attention_requested ?? false);
                    $flag = $manualAttention ? 'Requires attention' : $automaticFlag;
                    $flagColor = !$manualAttention && $automaticFlag ? $masterData->displayColorFor('order_flag', $automaticFlag) : null;
                    $flagReason = $manualAttention ? trim((string) ($job->attention_reason ?? '')) : '';
                    $deliveryOverdue = $job->delivery_date && !$job->completed_at && \App\Support\UserLocalTime::isDatePast($job->delivery_date);
                    $clientCode = strtoupper(trim((string) ($job->client?->code ?? '')));
                    $clientName = strtoupper(trim((string) ($job->client?->name ?? '')));
                    $clientRowTone = ($clientCode === 'IID' || preg_match('/\bIID\b/i', $clientName))
                        ? 'iid'
                        : (($clientCode === 'NEP' || preg_match('/\bNEP\b/i', $clientName)) ? 'nep' : '');
                @endphp
                <article @class(['ft-job-row', 'ft-client-row-'.$clientRowTone => $clientRowTone, 'is-bulk-selected' => $selectedOrderIdSet->contains((int) $job->id)]) wire:key="order-row-{{ $job->id }}">
                    <div class="ft-cell ft-created-cell" data-label="Created by / on">
                        <span class="ft-order-select-wrap">
                            @if($canDeleteOrders)
                                <input
                                    class="ft-order-select"
                                    type="checkbox"
                                    aria-label="Select {{ $job->displayOrderNumber() }}"
                                    @checked($selectedOrderIdSet->contains((int) $job->id))
                                    wire:change="toggleOrderSelection({{ $job->id }})"
                                >
                            @endif
                            <span><span class="ft-created-name">{{ $creatorName }}</span><time class="ft-created-on">{{ $job->created_at ? \App\Support\UserLocalTime::format($job->created_at, 'M j, Y · g:i A') : '—' }}</time></span>
                        </span>
                    </div>
                    <div class="ft-cell ft-identity" data-label="Order"><a class="ft-id" href="{{ route('jobs.index',['open'=>$job->id]) }}" wire:navigate.hover>{{ $job->displayOrderNumber() }}</a><span class="ft-sub">{{ $job->order_number ?: 'REF-'.str_pad((string)$job->id,5,'0',STR_PAD_LEFT) }}</span></div>
                    <div class="ft-cell ft-inquiry-cell" data-label="Inquiry">
                        @if($job->sourceInquiry)
                            @if(auth()->user()->canAccess('inquiries.view'))
                                <a class="ft-id" href="{{ route('inquiries.index', ['open' => $job->sourceInquiry->id]) }}" wire:navigate>{{ $job->sourceInquiry->inquiry_number }}</a>
                            @else
                                <span class="ft-client">{{ $job->sourceInquiry->inquiry_number }}</span>
                            @endif
                            <span class="ft-sub">{{ $job->sourceInquiry->reference_number ?: 'Source inquiry' }}</span>
                        @else
                            <span class="ft-standard-empty">Not linked</span>
                        @endif
                    </div>
                    <div class="ft-cell ft-brief" data-label="Client / products">
                        <span class="ft-job-client-logo-line"><x-ui.client-logo :client="$job->client" :name="$job->client?->name ?: 'Client'" :size="22" /><span class="ft-client">{{ $job->client?->name ?? '—' }}</span></span>
                        @if($productRows->count() === 1)
                            <span class="ft-product">{{ $productNames->first() ?: 'Product' }}</span>
                            <span class="ft-product-detail">{{ number_format($totalUnits) }} {{ \Illuminate\Support\Str::plural('pc', $totalUnits) }}</span>
                        @else
                            <span class="ft-product">{{ $productRows->count() }} ordered products · {{ number_format($totalUnits) }} pcs</span>
                            <span class="ft-product-detail" title="{{ $productNames->implode(' · ') }}">{{ $productNames->implode(' · ') }}</span>
                        @endif
                    </div>
                    <div class="ft-cell ft-stage-cell" data-label="Phase"><x-ui.phase-label :phase="$job->phase" :fallback="$phaseName" class="ft-pill" /></div>
                    <div class="ft-cell ft-flag-cell" data-label="Flag">@if($flag)<span class="ft-pill {{ $flagColor ? 'ft-master-color' : $tone($flag) }}" style="{{ \App\Support\MasterColor::style($flagColor) }}" @if($flagReason) title="{{ $flagReason }}" @endif>{{ $flag }}</span>@else<span class="ft-standard-empty">No flag</span>@endif</div>
                    <div class="ft-cell ft-owner-cell" data-label="Owner / delivery">
                        <div class="ft-owner">
                            <span class="ft-order-avatar">@if($ownerImage)<img src="{{ $ownerImage }}" alt="" loading="lazy" decoding="async">@else{{ $ownerInitials ?: 'FT' }}@endif</span>
                            <span class="ft-owner-copy"><span class="ft-owner-name">{{ $ownerName }}</span><time class="ft-due {{ $deliveryOverdue ? 'overdue' : '' }}">{{ $job->delivery_date ? 'Due '.$job->delivery_date->format('M j') : 'No delivery date' }}</time></span>
                        </div>
                    </div>
                    <div class="ft-cell ft-progress ft-progress-cell" data-label="Progress"><span class="ft-progress-track"><span class="ft-progress-fill" style="width:{{ max(0,min(100,(int)$job->progress)) }}%"></span></span><span>{{ (int)$job->progress }}%</span></div>
                    <div class="ft-row-actions" data-label="Actions" x-data="{ open: false }">
                            <button
                                class="ft-row-action-trigger"
                                type="button"
                                :aria-expanded="open ? 'true' : 'false'"
                                aria-haspopup="menu"
                                aria-controls="order-actions-{{ $job->id }}"
                                aria-label="Actions for {{ $job->displayOrderNumber() }}"
                                x-on:click.stop="
                                    const menu = $refs.menu;
                                    if (menu.matches(':popover-open')) { menu.hidePopover(); return; }
                                    const rect = $el.getBoundingClientRect();
                                    const menuWidth = 190;
                                    const menuHeight = {{ 46 + (($canViewFinance ? 1 : 0) + ($canDeleteOrders ? 1 : 0)) * 42 }};
                                    const edge = 10;
                                    const gap = 6;
                                    const left = Math.min(window.innerWidth - menuWidth - edge, Math.max(edge, rect.right - menuWidth));
                                    const openAbove = (window.innerHeight - rect.bottom) < (menuHeight + gap + edge) && rect.top > (menuHeight + gap + edge);
                                    const top = openAbove ? rect.top - menuHeight - gap : rect.bottom + gap;
                                    menu.style.left = `${left}px`;
                                    menu.style.top = `${Math.max(edge, top)}px`;
                                    menu.showPopover();
                                "
                            >⋮</button>
                            <div
                                id="order-actions-{{ $job->id }}"
                                class="ft-row-action-menu"
                                x-ref="menu"
                                popover="auto"
                                role="menu"
                                x-on:toggle="open = $event.newState === 'open'"
                            >
                                <a
                                    href="{{ route('jobs.index', ['open' => $job->id]) }}"
                                    role="menuitem"
                                    wire:navigate.hover
                                    x-on:click="$refs.menu.hidePopover()"
                                    aria-label="View details for {{ $job->displayOrderNumber() }}"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                                    <span>View</span>
                                </a>
                                @if($canViewFinance)
                                    <button
                                        type="button"
                                        role="menuitem"
                                        x-on:click="$refs.menu.hidePopover()"
                                        wire:click="openInvoiceAndPayment({{ $job->id }})"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3.5h12v17H6z"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>
                                        <span>Invoice and payment</span>
                                    </button>
                                @endif
                                @if($canDeleteOrders)
                                    <button class="ft-row-action-danger" type="button" role="menuitem" x-on:click="$refs.menu.hidePopover()" wire:click="deleteOrder({{ $job->id }})" wire:confirm="Delete {{ $job->displayOrderNumber() }}? This removes the order from active lists.">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg>
                                        <span>Delete order</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                </article>
            @empty
                <div class="ft-empty"><strong>No matching orders</strong>Try another order, inquiry, client, product, creator or owner.</div>
            @endforelse
            </div>

            <div class="ft-load-skeleton" wire:loading.delay.grid wire:target="search,gotoPage,previousPage,nextPage" aria-hidden="true"><span class="ft-skeleton-line"></span><span class="ft-skeleton-line"></span></div>
        </div>

        <div class="ft-list-footer">
            <span class="ft-result-count">@if($jobs->total()) Showing {{ $jobs->firstItem() }}–{{ $jobs->lastItem() }} of {{ number_format($jobs->total()) }} orders @else No orders found @endif</span>
            <nav class="ft-page-buttons" aria-label="Orders pagination">
                <button class="ft-page-button" type="button" wire:click="previousPage" @disabled($jobs->onFirstPage())>Previous</button>
                <span class="ft-page-buttons">
                    @php $previousRenderedPage = null; @endphp
                    @foreach($pageNumbers as $pageNumber)
                        @if($previousRenderedPage !== null && $pageNumber - $previousRenderedPage > 1)<span class="ft-page-ellipsis">…</span>@endif
                        <button type="button" class="ft-page-number {{ $pageNumber === $currentPage ? 'active' : '' }}" wire:click="gotoPage({{ $pageNumber }})" @if($pageNumber === $currentPage) aria-current="page" @endif>{{ $pageNumber }}</button>
                        @php $previousRenderedPage = $pageNumber; @endphp
                    @endforeach
                </span>
                <button class="ft-page-button" type="button" wire:click="nextPage" @disabled(!$jobs->hasMorePages())>Next</button>
            </nav>
        </div>
    </section>

</div>
