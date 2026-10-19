@props([
    'supplier',
    'selected' => false,
    'model' => 'createRfqSupplierIds',
])

@php
    $supplierId = (int) ($supplier['id'] ?? 0);
    $parts = preg_split('/\s+/u', trim((string) ($supplier['name'] ?? ''))) ?: [];
    $initials = strtoupper(mb_substr(implode('', array_map(fn ($part) => mb_substr($part, 0, 1), $parts)), 0, 2)) ?: '—';
    $badge = trim((string) ($supplier['badge'] ?? ''));
    $badgeTone = (string) ($supplier['badge_tone'] ?? '');
    $invitable = (bool) ($supplier['invitable'] ?? true);
    $emailReady = (bool) ($supplier['email_ready'] ?? filter_var((string) ($supplier['email'] ?? ''), FILTER_VALIDATE_EMAIL));
    $unavailableReason = trim((string) ($supplier['unavailable_reason'] ?? ''));
    $email = trim((string) ($supplier['email'] ?? ''));
    $shortCode = trim((string) ($supplier['short_code'] ?? '')) ?: (string) ($supplier['name'] ?? 'Supplier');
@endphp

<label {{ $attributes->class(['ft-create-rfq-supplier', 'is-selected' => $selected, 'is-unavailable' => ! $invitable]) }} @if(! $invitable) aria-disabled="true" @endif>
    <input
        type="checkbox"
        value="{{ $supplierId }}"
        wire:model.live="{{ $model }}"
        aria-label="Select {{ $shortCode }} for RFQ"
        @disabled(! $invitable)
    >
    <span class="ft-create-rfq-check" aria-hidden="true">
        <svg viewBox="0 0 16 16" fill="none"><path d="m3.3 8.1 2.7 2.7 6-6"></path></svg>
    </span>
    <span class="ft-create-rfq-avatar">{{ $initials }}</span>
    <span class="ft-create-rfq-supplier-copy">
        <strong>{{ $shortCode }}</strong>
        <small>{{ $supplier['name'] }} · {{ $supplier['category'] }} · {{ $email !== '' ? $email : 'No email configured' }}</small>
    </span>
    @if(! $invitable && $unavailableReason !== '')
        <span class="ft-create-rfq-badge is-blue">{{ $unavailableReason }}</span>
    @elseif(! $emailReady)
        <span class="ft-create-rfq-badge is-blue">No email configured</span>
    @elseif($badge !== '')
        <span class="ft-create-rfq-badge {{ $badgeTone === 'green' ? 'is-green' : 'is-blue' }}">{{ $badge }}</span>
    @endif
</label>
