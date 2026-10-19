@props([
    'workspace',
    'canManage' => false,
    'canEditSuppliers' => false,
])

@php
    $rows = collect($workspace['rows'] ?? []);
    $selectedCount = (int) ($workspace['selected_count'] ?? 0);
    $failedCount = (int) ($workspace['failed_count'] ?? 0);
    $visibleSelectableIds = $workspace['selectable_visible_ids'] ?? [];
    $allVisibleSelected = (bool) ($workspace['all_visible_selected'] ?? false);
@endphp

<section class="ft-rfq-workspace-card" aria-labelledby="rfq-workspace-title">
    <header class="ft-rfq-workspace-head">
        <div class="ft-rfq-workspace-heading">
            <h2 id="rfq-workspace-title">Request for quotation</h2>
            <p>Invite suppliers to submit a quotation through a secure email link.</p>
        </div>

        @if($canManage)
            <button type="button" class="ft-rfq-add-supplier-btn" wire:click="openRfqSupplierPicker" wire:loading.attr="disabled" wire:target="openRfqSupplierPicker">
                <span wire:loading.remove wire:target="openRfqSupplierPicker">Add supplier</span>
                <span wire:loading wire:target="openRfqSupplierPicker">Opening…</span>
            </button>
        @endif
    </header>

    <div class="ft-rfq-workspace-toolbar" role="search" aria-label="Filter RFQ suppliers">
        <label class="ft-rfq-workspace-search">
            <span class="sr-only">Search suppliers</span>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></svg>
            <input type="search" wire:model.live.debounce.300ms="rfqTableSearch" placeholder="Search suppliers" autocomplete="off">
        </label>

        <label class="ft-rfq-workspace-filter">
            <span class="sr-only">Email status</span>
            <select wire:model.live="rfqEmailStatusFilter">
                @foreach(($workspace['filter_options'] ?? []) as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m7 9.5 5 5 5-5"></path></svg>
        </label>

        @if($canManage)
        <button type="button" class="ft-rfq-email-settings-btn" wire:click="openRfqSettings">
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.5 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 8.5a1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.09A1.7 1.7 0 0 0 15.5 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.12.36.33.69.6 1 .3.27.68.42 1.09.4H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51.6Z"></path>
            </svg>
            <span>RFQ settings</span>
        </button>
        @endif
    </div>

    @if($selectedCount > 0)
        <div class="ft-rfq-selection-bar">
            <div class="ft-rfq-selection-copy">
                <span class="ft-rfq-selection-check" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none"><path d="m7 12 3 3 7-7"></path></svg>
                </span>
                <strong>{{ $selectedCount }} {{ \Illuminate\Support\Str::plural('supplier', $selectedCount) }} selected</strong>
            </div>
            <div class="ft-rfq-selection-actions">
                @if($canManage)
                    <button type="button" class="ft-rfq-send-selected-btn" wire:click="sendSelectedRfqEmails" wire:loading.attr="disabled" wire:target="sendSelectedRfqEmails">
                        <span wire:loading.remove wire:target="sendSelectedRfqEmails">Send {{ $selectedCount }} {{ \Illuminate\Support\Str::plural('email', $selectedCount) }}</span>
                        <span wire:loading wire:target="sendSelectedRfqEmails">Sending…</span>
                    </button>
                @endif
                <button type="button" class="ft-rfq-clear-selection-btn" wire:click="clearRfqSelection">Clear selection</button>
            </div>
        </div>
    @endif

    @error('rfqDelivery')
        <div class="ft-rfq-workspace-alert is-danger" role="alert" x-data="{ visible: true }" x-show="visible" x-transition.opacity>
            <span class="ft-rfq-workspace-alert-icon" aria-hidden="true">!</span>
            <span>{{ $message }}</span>
            <button type="button" x-on:click="visible = false" aria-label="Dismiss RFQ email error">×</button>
        </div>
    @else
        @if($failedCount > 0)
            <div class="ft-rfq-workspace-alert is-danger" role="status" x-data="{ visible: true }" x-show="visible" x-transition.opacity>
                <span class="ft-rfq-workspace-alert-icon" aria-hidden="true">!</span>
                <span>{{ $failedCount }} {{ \Illuminate\Support\Str::plural('email', $failedCount) }} failed to send. Review the error and retry.</span>
                <button type="button" x-on:click="visible = false" aria-label="Dismiss failed email alert">×</button>
            </div>
        @endif
    @enderror

    <div class="ft-rfq-management-table-shell">
        <div class="ft-rfq-management-scroll">
            <table class="ft-rfq-management-table">
                <thead>
                    <tr>
                        <th class="ft-rfq-checkbox-col">
                            <button
                                type="button"
                                class="ft-rfq-master-checkbox {{ $allVisibleSelected ? 'is-checked' : '' }}"
                                wire:click='toggleVisibleRfqSelection(@json($visibleSelectableIds))'
                                @disabled($visibleSelectableIds === [])
                                aria-label="{{ $allVisibleSelected ? 'Clear visible supplier selection' : 'Select visible suppliers' }}"
                                aria-pressed="{{ $allVisibleSelected ? 'true' : 'false' }}"
                            >
                                @if($allVisibleSelected)
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m6.5 12 3.5 3.5 7.5-8"></path></svg>
                                @endif
                            </button>
                        </th>
                        <th>Supplier</th>
                        <th>Email</th>
                        <th class="ft-rfq-email-status-col">Email status</th>
                        <th>RFQ status</th>
                        <th>Last activity</th>
                        <th class="ft-rfq-actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <x-inquiries.rfq-management-row
                            :row="$row"
                            :can-manage="$canManage"
                            :can-edit-supplier="$canEditSuppliers"
                            :selected-ids="$workspace['selected_ids'] ?? []"
                        />
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="ft-rfq-management-empty">
                                    <strong>No suppliers found</strong>
                                    <span>Try another search or email-status filter.</span>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <footer class="ft-rfq-management-footer">
            <p>Only suppliers with a configured email can be selected for bulk sending.</p>
            <div class="ft-rfq-pagination">
                <span>{{ number_format((int) ($workspace['first'] ?? 0)) }}–{{ number_format((int) ($workspace['last'] ?? 0)) }} of {{ number_format((int) ($workspace['total'] ?? 0)) }}</span>
                <button type="button" wire:click="setRfqTablePage({{ max(1, (int) ($workspace['current_page'] ?? 1) - 1) }})" @disabled(! ($workspace['has_previous'] ?? false)) aria-label="Previous RFQ supplier page">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m14.5 6.5-5.5 5.5 5.5 5.5"></path></svg>
                </button>
                <button type="button" wire:click="setRfqTablePage({{ min((int) ($workspace['last_page'] ?? 1), (int) ($workspace['current_page'] ?? 1) + 1) }})" @disabled(! ($workspace['has_next'] ?? false)) aria-label="Next RFQ supplier page">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m9.5 6.5 5.5 5.5-5.5 5.5"></path></svg>
                </button>
            </div>
        </footer>
    </div>
</section>
