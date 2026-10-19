@props([
    'supplier',
])

@php
    $supplierId = (int) data_get($supplier, 'id');
    $name = trim((string) data_get($supplier, 'name')) ?: 'Supplier';
    $email = trim((string) data_get($supplier, 'email'));
    $contact = trim((string) data_get($supplier, 'contact'));
    $code = trim((string) data_get($supplier, 'code'));
    $shortCode = trim((string) data_get($supplier, 'short_code')) ?: $name;
    $parts = preg_split('/\s+/u', $name) ?: [];
    $initials = strtoupper(mb_substr(implode('', array_map(fn ($part) => mb_substr($part, 0, 1), $parts)), 0, 2)) ?: 'S';
    $secondary = collect([
        $shortCode !== $name ? $name : null,
        $contact !== '' ? $contact : null,
        $email !== '' ? $email : 'No email configured',
        $code !== '' ? 'Code '.$code : null,
    ])->filter()->implode(' · ');
@endphp

<article {{ $attributes->class(['ft-create-rfq-selected-card']) }}>
    <span class="ft-create-rfq-selected-avatar" aria-hidden="true">{{ $initials }}</span>

    <span class="ft-create-rfq-selected-copy">
        <strong title="{{ $name }}">{{ $shortCode }}</strong>
        <small title="{{ $secondary }}">{{ $secondary }}</small>
    </span>

    <span class="ft-create-rfq-selected-status">
        <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m3.3 8.1 2.7 2.7 6-6"></path></svg>
        Selected
    </span>

    <button
        type="button"
        class="ft-create-rfq-selected-remove"
        wire:click="removeCreateRfqSupplier({{ $supplierId }})"
        wire:loading.attr="disabled"
        wire:target="removeCreateRfqSupplier({{ $supplierId }})"
        aria-label="Remove {{ $name }} from RFQ"
    >
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="M4 6h12M8 6V4h4v2M7 8.5v6M10 8.5v6M13 8.5v6M6 6l.6 10h6.8L14 6"></path>
        </svg>
        <span wire:loading.remove wire:target="removeCreateRfqSupplier({{ $supplierId }})">Remove</span>
        <span wire:loading wire:target="removeCreateRfqSupplier({{ $supplierId }})">Removing…</span>
    </button>
</article>
