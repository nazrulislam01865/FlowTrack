    @php
        $orderToolbarAnyFilterActive = filled($searchFilter)
            || filled($clientFilter)
            || filled($ownerFilter)
            || $holdOnFilter
            || filled($phaseFilter)
            || filled($dateFrom)
            || filled($dateTo)
            || filled($metricFilter)
            || $dashboardScope
            || (int) $importFilterId > 0
            || $selectedStageFiltersActive;
        $orderToolbarAllActive = ! $orderToolbarAnyFilterActive;
    @endphp

    <section class="list-card orders-table-card" aria-label="Orders">
        <div class="filter-toolbar ft-order-filter-layout">
            <div class="ft-order-filter-search-row">
                <label class="search-box">
                    <span aria-hidden="true">⌕</span>
                    <input
                        type="search"
                        autocomplete="off"
                        placeholder="Search order, reference, client or product"
                        wire:model.live.debounce.700ms="search"
                        aria-label="Search orders"
                    >
                </label>
            </div>

            <div class="ft-order-filter-controls-row ft-order-filter-main-row" aria-label="Order filters">
                <button
                    type="button"
                    class="ft-order-filter-chip {{ $orderToolbarAllActive ? 'active' : '' }}"
                    wire:click="clearFilters"
                    aria-pressed="{{ $orderToolbarAllActive ? 'true' : 'false' }}"
                >All</button>

                <label class="ft-order-filter-chip ft-order-hold-filter {{ $holdOnFilter ? 'active' : '' }}">
                    <input type="checkbox" wire:model.live="holdOn" @checked($holdOnFilter)>
                    <span>Hold on</span>
                </label>

                <select class="ft-order-native-filter" wire:model.live="phase" aria-label="Workflow stage filter">
                    <option value="">All stages</option>
                    @foreach($stages as $stage)
                        <option value="{{ data_get($stage, 'id') }}">{{ data_get($stage, 'name') }}</option>
                    @endforeach
                </select>

                <x-ui.search-select
                    class="ft-order-v5-search-select ft-order-v5-client-filter"
                    label="Client"
                    property="client"
                    type="clients"
                    context="jobs"
                    :value="$clientFilter"
                    placeholder="All clients"
                    :initial-options="$clientFilterOptions"
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="300"
                    wire:key="order-v5-client-filter-{{ filled($clientFilter) ? $clientFilter : 'all' }}"
                />

                <x-ui.search-select
                    class="ft-order-v5-search-select ft-order-v5-owner-filter"
                    label="Owner"
                    property="owner"
                    type="users"
                    context="order-list-user-filter"
                    :value="$ownerFilter"
                    placeholder="All owners"
                    :initial-options="$ownerFilterOptions"
                    :show-avatar="true"
                    search-placeholder="Search user..."
                    footer-message="All active FlowTrack users are available."
                    :hide-label="true"
                    :fixed-menu="true"
                    :menu-width="300"
                    action="applyOwnerFilter"
                    wire:key="order-v5-owner-filter-{{ filled($ownerFilter) ? $ownerFilter : 'all' }}"
                />

                <x-ui.date-range
                    class="ft-order-list-date-range"
                    from-property="dateFrom"
                    to-property="dateTo"
                    :from-value="$dateFrom"
                    :to-value="$dateTo"
                    label="{{ $dashboardScope ? 'Activity date range' : 'Created date range' }}"
                    from-label="From"
                    to-label="To"
                />

                <x-ui.filter-reset
                    class="chip ft-order-filter-reset ft-order-clear-filter"
                    action="clearFilters"
                    label="Clear"
                    icon="×"
                    :disabled="! $orderToolbarAnyFilterActive"
                    aria-label="Clear active order filters"
                />
            </div>
        </div>

        @if($dashboardScope)
            <div class="import-filter-line" role="status">
                <b>Dashboard period:</b>
                {{ $dashboardRangeDays === 1 ? 'Today' : ($dashboardRangeDays > 0 ? $dashboardRangeDays.' days' : $dateFrom.' to '.$dateTo) }}
                <span>· Orders active in this stage and updated during the selected period</span>
                @if(filled($dashboardTeamLabel))<span>· Team: {{ $dashboardTeamLabel }}</span>@endif
            </div>
        @endif

        @if($selectedStage)
            <div class="stage-inline-controls">
                <span class="stage-inline-label">{{ $stageName }}</span>
                <div class="stage-inline-quick" role="group" aria-label="{{ $stageName }} status filters">
                    @foreach($stageQuickFilters as $key => $label)
                        @php
                            $quickColor = (string) data_get($stageQuickMeta, $key.'.color', '#0F8F7C');
                        @endphp
                        <button
                            type="button"
                            class="stage-inline-chip {{ $stageQuick === $key ? 'active' : '' }}"
                            style="--quick-color:{{ $quickColor }}"
                            wire:click="setStageQuick('{{ $key }}')"
                            aria-pressed="{{ $stageQuick === $key ? 'true' : 'false' }}"
                        >
                            <span class="stage-inline-check" aria-hidden="true">✓</span>
                            <span>{{ $label }}</span>
                        </button>
                    @endforeach
                </div>
                <span class="stage-view-note">Row colors match {{ strtolower($stageName) }} status</span>
                <div class="stage-inline-selects">
                    @if(in_array($sequence, [1,2,3,4], true))
                        <div class="stage-filter-field">
                            <span class="stage-filter-caption">Supplier</span>
                            <x-ui.search-select
                                class="ft-order-v5-stage-search-select ft-order-v5-supplier-filter"
                                label="Supplier"
                                property="stageSupplier"
                                type="suppliers"
                                context="order-list"
                                :value="$stageSupplier"
                                placeholder="All suppliers"
                                :initial-options="$supplierFilterOptions"
                                search-placeholder="Search supplier..."
                                footer-message="Type 2 characters to search suppliers."
                                :hide-label="true"
                                :fixed-menu="true"
                                :menu-width="320"
                                wire:key="order-v5-stage-supplier-{{ $sequence }}-{{ filled($stageSupplier) ? $stageSupplier : 'all' }}"
                            />
                        </div>

                        @php
                            $stageAssigneeLabel = $sequence === 1 ? 'Order owner' : $stageName.' assignee';
                            $stageAssigneePlaceholder = $sequence === 1
                                ? 'All order owners'
                                : 'All '.strtolower($stageName).' assignees';
                        @endphp
                        <div class="stage-filter-field stage-filter-field-user">
                            <span class="stage-filter-caption">{{ $stageAssigneeLabel }}</span>
                            <x-ui.search-select
                                class="ft-order-v5-stage-search-select ft-order-v5-stage-assignee-filter"
                                :label="$stageAssigneeLabel"
                                property="stageAssignee"
                                type="users"
                                context="order-list-user-filter"
                                :value="$stageAssignee"
                                :placeholder="$stageAssigneePlaceholder"
                                :initial-options="$stageAssigneeOptions"
                                :show-avatar="true"
                                search-placeholder="Search user..."
                                footer-message="All active FlowTrack users are available."
                                :hide-label="true"
                                :fixed-menu="true"
                                :menu-width="340"
                                wire:key="order-v5-stage-assignee-{{ $sequence }}-{{ filled($stageAssignee) ? $stageAssignee : 'all' }}"
                            />
                        </div>
                    @elseif($sequence === 5)
                        <label class="stage-filter-field">
                            <span class="stage-filter-caption">Shipping urgency</span>
                            <select wire:model.live="stageUrgency" aria-label="Shipping urgency filter">
                                <option value="">All shipping urgency</option>
                                @foreach($shipmentUrgencyOptions as $option)
                                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="stage-filter-field">
                            <span class="stage-filter-caption">Carrier</span>
                            <select wire:model.live="stageCarrier" aria-label="Carrier filter">
                                <option value="">All carrier</option>
                                <option>UPS</option><option>FedEx</option><option>DHL</option><option>Other</option>
                            </select>
                        </label>
                    @elseif(in_array($sequence, [6,7], true))
                        <div class="stage-filter-field">
                            <span class="stage-filter-caption">Client</span>
                            <x-ui.search-select
                                class="ft-order-v5-stage-search-select ft-order-v5-stage-client-filter"
                                label="Client"
                                property="stageClient"
                                type="clients"
                                context="jobs"
                                :value="$stageClient"
                                placeholder="All clients"
                                :initial-options="$stageClientFilterOptions"
                                search-placeholder="Search client..."
                                :hide-label="true"
                                :fixed-menu="true"
                                :menu-width="320"
                                wire:key="order-v5-stage-client-{{ $sequence }}-{{ filled($stageClient) ? $stageClient : 'all' }}"
                            />
                        </div>

                        <div class="stage-filter-field stage-filter-field-user">
                            <span class="stage-filter-caption">Finance owner</span>
                            <x-ui.search-select
                                class="ft-order-v5-stage-search-select ft-order-v5-stage-assignee-filter"
                                label="Finance owner"
                                property="stageAssignee"
                                type="users"
                                context="order-list-user-filter"
                                :value="$stageAssignee"
                                placeholder="All finance owners"
                                :initial-options="$stageAssigneeOptions"
                                :show-avatar="true"
                                search-placeholder="Search user..."
                                footer-message="All active FlowTrack users are available."
                                :hide-label="true"
                                :fixed-menu="true"
                                :menu-width="340"
                                wire:key="order-v5-finance-owner-{{ $sequence }}-{{ filled($stageAssignee) ? $stageAssignee : 'all' }}"
                            />
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if($importFilterId)
            <div class="import-filter-line"><b>Imported batch:</b> {{ $importFilterLabel ?: '#'.$importFilterId }}</div>
        @endif

        <div class="active-filter-line">
            <span>{{ number_format($jobs->total()) }} {{ \Illuminate\Support\Str::plural('order', $jobs->total()) }}</span>
            <span>{{ $selectedStage ? $stageName.' filter'.($dashboardScope ? ' · dashboard period applied' : ' · same Orders page') : 'Showing all workflow stages' }}</span>
        </div>
