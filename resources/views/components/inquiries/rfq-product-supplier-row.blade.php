@props([
    'row',
    'canManage' => false,
    'canEditSupplier' => false,
    'selectedKeys' => [],
])

@php
    $supplierId = (int) ($row['supplier_id'] ?? 0);
    $selectionKey = (string) ($row['selection_key'] ?? '');
    $action = $row['action'] ?? ['type' => 'disabled', 'label' => 'Unavailable', 'tone' => 'neutral'];
    $email = trim((string) ($row['email'] ?? ''));
    $checked = collect($selectedKeys)->map(fn ($key) => (string) $key)->contains($selectionKey);
@endphp

<tr wire:key="rfq-product-supplier-{{ $selectionKey }}">
    <td class="ft-rfq-px-check-col">
        <input
            type="checkbox"
            class="ft-rfq-px-checkbox"
            wire:model.live="rfqSelectedProductSupplierKeys"
            value="{{ $selectionKey }}"
            @disabled(! ($row['selectable'] ?? false))
            @checked($checked)
            aria-label="Select {{ $row['supplier_short_code'] ?? ($row['supplier_name'] ?? 'supplier') }} for this product"
        >
    </td>
    <td data-label="Supplier">
        <strong class="ft-rfq-px-supplier-name" title="{{ $row['supplier_name'] ?? 'Supplier' }}">{{ $row['supplier_short_code'] ?? ($row['supplier_name'] ?? 'Supplier') }}</strong>
    </td>
    <td data-label="Email">
        <span class="ft-rfq-px-email {{ $email === '' ? 'is-missing' : '' }}">{{ $email !== '' ? $email : 'No email configured' }}</span>
    </td>
    <td data-label="Invitation status">
        <div class="ft-rfq-px-status-stack">
            <span class="ft-rfq-px-status is-{{ $row['status_tone'] ?? 'neutral' }}">{{ $row['status_label'] ?? '—' }}</span>
            @if(($row['status_key'] ?? '') !== 'failed' && filled($row['status_detail'] ?? null))
                <small>{{ $row['status_detail'] }}</small>
            @endif
        </div>
    </td>
    <td data-label="Quotation">
        @if($row['quotation_received'] ?? false)
            <span class="ft-rfq-px-quotation is-received">Received</span>
        @else
            <span class="ft-rfq-px-dash">—</span>
        @endif
    </td>
    <td data-label="Last activity">
        <span class="ft-rfq-px-last-activity">
            @if($row['last_activity_at'] ?? null)
                {{ \App\Support\UserLocalTime::format($row['last_activity_at'], 'M j, Y') }} · {{ \App\Support\UserLocalTime::format($row['last_activity_at'], 'g:i A') }}
            @else
                —
            @endif
        </span>
    </td>
    <td class="ft-rfq-px-action-col" data-label="Actions">
        @if(($action['type'] ?? '') === 'setup_email')
            @if($canEditSupplier)
                <a href="{{ route('master-data', ['group' => 'supplier', 'edit_supplier' => $supplierId]) }}" wire:navigate class="ft-rfq-px-row-action is-warning">Set up email</a>
            @else
                <button type="button" class="ft-rfq-px-row-action is-warning" disabled>Set up email</button>
            @endif
        @elseif(($action['type'] ?? '') === 'send' && $canManage)
            <button
                type="button"
                class="ft-rfq-px-row-action is-{{ $action['tone'] ?? 'success' }}"
                wire:click="sendRfqSupplierEmail({{ $supplierId }})"
                wire:loading.attr="disabled"
                wire:target="sendRfqSupplierEmail({{ $supplierId }})"
            >
                <span wire:loading.remove wire:target="sendRfqSupplierEmail({{ $supplierId }})">{{ $action['label'] ?? 'Send invitation' }}</span>
                <span wire:loading wire:target="sendRfqSupplierEmail({{ $supplierId }})">Sending…</span>
            </button>
        @else
            <button type="button" class="ft-rfq-px-row-action is-neutral" disabled>{{ $action['label'] ?? 'Unavailable' }}</button>
        @endif
    </td>
</tr>
