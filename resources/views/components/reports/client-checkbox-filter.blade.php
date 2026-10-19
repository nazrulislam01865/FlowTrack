@props([
    'clients',
    'selectedIds' => [],
])

@if($clients->isNotEmpty())
    @php
        $selectedClientIds = collect($selectedIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->all();
        $selectedCount = count($selectedClientIds);
    @endphp

    <div class="ft-osr-client-filter" aria-label="Client filters">
        <div class="ft-osr-client-filter-head">
            <div>
                <div class="ft-osr-client-filter-label">CLIENT</div>
                <div class="ft-osr-client-filter-help">Select one or more clients. Leave all unchecked to show every client.</div>
            </div>
        </div>

        <div class="ft-osr-client-options" role="group" aria-label="Filter report by client">
            @foreach($clients as $client)
                @php($selected = in_array((int) $client->id, $selectedClientIds, true))
                <label
                    class="ft-osr-client-option {{ $selected ? 'is-selected' : '' }}"
                    wire:key="order-summary-client-filter-{{ $client->id }}"
                >
                    <input
                        type="checkbox"
                        value="{{ $client->id }}"
                        wire:model.live="clientIds"
                    >
                    <span>{{ $client->name }}</span>
                </label>
            @endforeach

            @if($selectedCount > 0)
                <button type="button" wire:click="clearClientFilter" class="ft-osr-clear-filters" title="Clear filters">
                    <span class="clear-filter-icon" aria-hidden="true">×</span>
                    <span>Clear</span>
                </button>
            @endif
        </div>
    </div>
@endif
