@props([
    'selectedInquiries' => collect(),
    'initialOptions' => collect(),
    'clientId' => null,
    'selectorVersion' => 0,
])
@php
    $selected = collect($selectedInquiries)
        ->filter(fn ($item) => is_array($item) && filled($item['id'] ?? null) && filled($item['label'] ?? null))
        ->values();
    $selectedIds = $selected->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
    $excludeIds = $selectedIds->implode(',');
@endphp
{{-- Revealing this picker is intentionally query-free. The remote Inquiry page
     is fetched only after the user explicitly clicks the search selector. --}}
<div
    class="ft-create-field ft-create-inquiry-link"
    data-create-inquiry-link
    wire:key="create-inquiry-link-{{ $selectorVersion }}"
    x-data="{ editing: @js($selected->isEmpty()) }"
    x-on:flowtrack-create-inquiry-picker-open.window="editing = true"
>
    <b>Search and Link Inquiry</b>
    <small class="ft-create-inquiry-help">Search and link this order to existing inquiries (optional).</small>

    @if($selected->isNotEmpty())
        <div class="ft-create-inquiry-selected-list" aria-label="Selected inquiries">
            @foreach($selected as $index => $inquiry)
                <div class="ft-create-inquiry-selected" wire:key="create-order-selected-inquiry-{{ (int) $inquiry['id'] }}">
                    <span class="ft-create-inquiry-link-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M9.5 14.5 14.5 9.5"/>
                            <path d="m7.4 16.6-1.2 1.2a3.4 3.4 0 0 1-4.8-4.8l3.7-3.7a3.4 3.4 0 0 1 4.8 0"/>
                            <path d="m16.6 7.4 1.2-1.2a3.4 3.4 0 0 1 4.8 4.8l-3.7 3.7a3.4 3.4 0 0 1-4.8 0"/>
                        </svg>
                    </span>
                    <span class="ft-create-inquiry-selected-copy">
                        <strong title="{{ $inquiry['label'] }}">{{ $inquiry['label'] }}</strong>
                        <small>
                            <i aria-hidden="true"></i>
                            {{ $index === 0 ? 'Primary linked inquiry' : 'Linked inquiry' }}
                            @if(filled($inquiry['meta'] ?? null))
                                <span class="ft-create-inquiry-meta">· {{ $inquiry['meta'] }}</span>
                            @endif
                        </small>
                    </span>
                    <span class="ft-create-inquiry-row-actions">
                        <button
                            type="button"
                            class="ft-create-inquiry-change"
                            wire:click="openCreateInquiryPicker({{ (int) $inquiry['id'] }})"
                            wire:loading.attr="disabled"
                            wire:target="openCreateInquiryPicker"
                        >Change</button>
                        <button
                            type="button"
                            class="ft-create-inquiry-remove"
                            wire:click="removeCreateInquiry({{ (int) $inquiry['id'] }})"
                            wire:loading.attr="disabled"
                            wire:target="removeCreateInquiry"
                            aria-label="Remove {{ $inquiry['label'] }}"
                            title="Remove inquiry"
                        >×</button>
                    </span>
                </div>
            @endforeach
        </div>

        <div class="ft-create-inquiry-actions">
            <span class="ft-create-inquiry-count">{{ $selected->count() }} {{ $selected->count() === 1 ? 'inquiry' : 'inquiries' }} selected</span>
            <button
                type="button"
                class="ft-create-inquiry-add"
                wire:click="openCreateInquiryPicker"
                wire:loading.attr="disabled"
                wire:target="openCreateInquiryPicker"
            >+ Add another inquiry</button>
        </div>
    @endif

    <div
        class="ft-create-inquiry-picker-wrap"
        x-ref="picker"
        x-cloak
        x-show="editing || @js($selected->isEmpty())"
    >
        <div class="ft-create-inquiry-picker">
            <svg class="ft-create-inquiry-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="11" cy="11" r="6.5"/>
                <path d="m16 16 4 4"/>
            </svg>
            <x-ui.search-select
                class="ft-create-inquiry-select"
                label="Search and Link Inquiry"
                property="createInquiryId"
                type="inquiries"
                context="create-job"
                action="setCreateSelector"
                :value="null"
                placeholder="Search by inquiry number or title"
                search-placeholder="Search by inquiry number or title"
                :selected-label="null"
                :initial-options="$initialOptions"
                :params="['client_id' => $clientId, 'exclude_ids' => $excludeIds]"
                :clearable="false"
                :hide-label="true"
                :fixed-menu="true"
                :menu-width="620"
                :infinite-scroll="true"
                wire:key="create-inquiry-picker-{{ $selectorVersion }}"
            />
        </div>
        @if($selected->isNotEmpty())
            <div class="ft-create-inquiry-picker-footer">
                <small>Select an Inquiry to add it or replace the one you chose to change.</small>
                <button
                    type="button"
                    class="ft-create-inquiry-cancel"
                    wire:click="cancelCreateInquiryPicker"
                    x-on:click="editing = false"
                >Cancel</button>
            </div>
        @endif
    </div>

    @error('createInquiryId')<small class="validation-error">{{ $message }}</small>@enderror
    @error('createInquiryIds')<small class="validation-error">{{ $message }}</small>@enderror
    @error('createInquiryIds.*')<small class="validation-error">{{ $message }}</small>@enderror
</div>
