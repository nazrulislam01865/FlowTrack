        <div class="ft-master-breadcrumb" aria-label="Breadcrumb">
            <span>{{ $masterSectionLabel }}</span><i>/</i><strong>{{ $pageTitle }}</strong>
        </div>

        <div class="ft-master-page-head">
            <div>
                <h1>{{ $pageTitle }}</h1>
                <p>{{ $pageSubtitle }}</p>
            </div>
            @if($canCreateMaster)
                @if($group === 'supplier')
                    <a href="{{ route('master-data', ['group' => 'supplier', 'create' => 1]) }}" wire:navigate class="primary ft-master-add-button">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        <span>Create supplier</span>
                    </a>
                @else
                    <button type="button" class="primary ft-master-add-button" wire:click="open">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                        <span>Add {{ $singularLabel }}</span>
                    </button>
                @endif
            @endif
        </div>

        @if(session('success'))<div class="flash success ft-master-flash">{{ session('success') }}</div>@endif
        @error('record')<div class="flash error ft-master-flash">{{ $message }}</div>@enderror

        <div class="ft-master-single-stat ft-master-generic-stat">
            <div class="ft-master-stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5h14v14H5zM8 9h8M8 13h8M8 17h5"/></svg>
            </div>
            <div class="ft-master-stat-copy">
                <span>Total {{ strtolower($pageTitle) }}</span>
                <strong>{{ number_format($selectedTotal) }}</strong>
            </div>
            <small>{{ number_format($selectedActive) }} active</small>
        </div>

        <section @class(['ft-master-generic-card', 'ft-master-supplier-card' => $group === 'supplier'])>
            <div class="ft-master-generic-toolbar">
                <label class="ft-master-search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search {{ strtolower($pageTitle) }}..." aria-label="Search {{ strtolower($pageTitle) }}">
                </label>
            </div>

            <div class="ft-master-product-count">
                @if($recordsReady && $rows)
                    Showing {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} of {{ number_format($rows->total()) }} records
                @else
                    Loading records…
                @endif
            </div>

            @if(!$recordsReady)
                @include('livewire.shared.table-rows-placeholder', ['columns' => $columnCount, 'rows' => 8])
            @else
                <div class="table-wrap ft-master-generic-table-wrap" wire:key="master-records-{{ $group }}">
                    <table @class(['master-table', 'ft-master-generic-table', 'ft-master-supplier-table' => $group === 'supplier'])>
                        <thead>
                            <tr>
                                <th>Sort order</th>
                                <th>Code</th>
                                @if($group !== 'remote_area')<th>{{ $group === 'phone_country_code' ? 'Phone code' : 'Name' }}</th>@endif
                                @if($group === 'remote_area')<th>Carrier</th><th>Country</th><th>Postal range / City</th><th>Origin surcharge</th><th>Destination surcharge</th><th>Extra charge</th>@endif
                                @if($group === 'task_pack_work_calendar')<th>Days</th><th>Working hours</th>@endif
                                @if($group === 'inquiry_task_status')<th>Inquiry status auto</th><th>Flag</th>@endif
                                @if($group === 'order_task_status')<th>Automatic task flag</th>@endif
                                @if($group === 'order_task_flag')<th>Order flag</th>@endif
                                @if($hasParent)<th>{{ $group === 'state' ? 'Country' : 'Product Category' }}</th>@endif
                                @if($group !== 'remote_area')<th>Description / Use</th>@endif
                                @if($hasColor)<th>Color</th>@endif
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($rows as $r)
                            <tr>
                                <td class="ft-master-mobile-sort" data-label="Sort order">{{ $r->sort_order }}</td>
                                <td class="ft-master-mobile-code" data-label="Code"><strong class="ft-master-product-code">{{ $r->code }}</strong></td>
                                @if($group !== 'remote_area')<td class="ft-master-mobile-name" data-label="{{ $group === 'phone_country_code' ? 'Phone code' : 'Name' }}">{{ $r->name }}</td>@endif
                                @if($group === 'remote_area')
                                    @php $remoteAreaExtraCharge = $r->remoteAreaExtraCharge(); @endphp
                                    <td data-label="Carrier"><strong>{{ $r->remoteAreaCarrier() ?: '—' }}</strong></td>
                                    <td data-label="Country">
                                        <strong>{{ $r->remoteAreaCountry() ?: '—' }}</strong>
                                        @if($r->remoteAreaIataCode() !== '')<div class="small muted">{{ $r->remoteAreaIataCode() }}</div>@endif
                                    </td>
                                    <td data-label="Postal range / City"><strong>{{ $r->remoteAreaLocationLabel() }}</strong></td>
                                    <td data-label="Origin surcharge">{{ $r->remoteAreaOriginSurcharge() ?: 'No' }}</td>
                                    <td data-label="Destination surcharge">{{ $r->remoteAreaDestinationSurcharge() ?: 'No' }}</td>
                                    <td data-label="Extra charge">{{ $remoteAreaExtraCharge === null ? '—' : number_format($remoteAreaExtraCharge, 2) }}</td>
                                @endif
                                @if($group === 'task_pack_work_calendar')
                                    <td data-label="Days"><strong class="ft-work-calendar-table-value">{{ $r->taskPackWorkCalendarDayRange() }}</strong></td>
                                    <td data-label="Working hours"><strong class="ft-work-calendar-table-value">{{ $r->taskPackWorkCalendarTimeRange() }}</strong></td>
                                @endif
                                @if($group === 'inquiry_task_status')
                                    <td class="ft-master-mobile-auto-status" data-label="Inquiry status auto"><strong>{{ $r->inquiryAutoStatus() }}</strong></td>
                                    <td class="ft-master-mobile-flag" data-label="Flag">
                                        @if($r->requiresAttention())
                                            <span class="ft-inquiry-status-rule-flag is-attention">Requires attention</span>
                                        @else
                                            <span class="ft-inquiry-status-rule-flag">Not needed</span>
                                        @endif
                                    </td>
                                @endif
                                @if($group === 'order_task_status')
                                    @php $mappedTaskFlag = $orderTaskFlagOptions->firstWhere('id', $r->orderTaskFlagId()); @endphp
                                    <td class="ft-master-mobile-flag" data-label="Automatic task flag">
                                        @if($mappedTaskFlag)
                                            <span class="ft-inquiry-status-rule-flag is-attention" style="{{ \App\Support\MasterColor::style($mappedTaskFlag->color) }}">{{ $mappedTaskFlag->name }}</span>
                                        @else
                                            <span class="ft-inquiry-status-rule-flag">No flag</span>
                                        @endif
                                    </td>
                                @endif
                                @if($group === 'order_task_flag')
                                    @php $mappedOrderFlag = $orderFlagOptions->firstWhere('id', $r->orderFlagId()); @endphp
                                    <td class="ft-master-mobile-flag" data-label="Order flag">
                                        <strong>{{ $mappedOrderFlag?->name ?? 'Not mapped' }}</strong>
                                    </td>
                                @endif
                                @if($hasParent)<td class="ft-master-mobile-parent" data-label="{{ $group === 'state' ? 'Country' : 'Product Category' }}">{{ $r->parent?->name ?? '—' }}</td>@endif
                                @if($group !== 'remote_area')<td class="ft-master-mobile-description" data-label="Description / Use">{{ $r->description ?: '—' }}</td>@endif
                                @if($hasColor)
                                    @php
                                        $rowColor = \App\Support\MasterColor::normalize($r->color) ?: \App\Support\MasterColor::defaultFor($group, $r->name);
                                    @endphp
                                    <td class="ft-master-mobile-color" data-label="Color">
                                        <label class="ft-master-color-chip" style="{{ \App\Support\MasterColor::style($rowColor) }}" title="Choose color for {{ $r->name }}">
                                            <input
                                                class="ft-master-inline-color"
                                                type="color"
                                                value="{{ $rowColor }}"
                                                wire:change="updateColor({{ $r->id }}, $event.target.value)"
                                                wire:loading.attr="disabled"
                                                @disabled(!$canEditMaster)
                                                aria-label="Choose color for {{ $r->name }}"
                                            >
                                            <span>{{ $rowColor }}</span>
                                        </label>
                                    </td>
                                @endif
                                <td class="ft-master-mobile-status" data-label="Status"><x-ui.badge :label="$r->status === 'active' ? 'Active' : 'Inactive'" /></td>
                                <td class="ft-master-mobile-actions" data-label="Actions">
                                    <div class="row-actions">
                                        @if($canEditMaster)
                                            <button class="mini-btn" wire:click="open({{ $r->id }})">Edit</button>
                                            <button class="mini-btn" wire:click="toggle({{ $r->id }})">{{ $r->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                        @endif
                                        @if($canDeleteMaster)
                                            <button class="mini-btn" wire:click="deleteRecord({{ $r->id }})" wire:confirm="Delete this master record?">Delete</button>
                                        @endif
                                        @if(!$canEditMaster && !$canDeleteMaster)
                                            <span class="small muted">View only</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr class="ft-master-empty-row"><td colspan="{{ $columnCount }}"><div class="empty-state">No records found.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if($rows->total() > 30)
                    <div class="ft-list-pagination ft-master-pagination">
                        <span>Showing <b>{{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }}</b> of {{ $rows->total() }} records</span>
                        <div class="ft-page-actions">
                            <button type="button" wire:click="previousPage('masterPage')" @disabled($rows->onFirstPage())>Previous</button>
                            <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
                            <button type="button" wire:click="nextPage('masterPage')" @disabled(!$rows->hasMorePages())>Next</button>
                        </div>
                    </div>
                @endif
            @endif
        </section>
