@props([
    'row',
    'canManage' => false,
    'canEditSupplier' => false,
    'selectedIds' => [],
])

@php
    $supplierId = (int) ($row['supplier_id'] ?? 0);
    $action = $row['action'] ?? ['type' => 'disabled', 'label' => 'Unavailable', 'tone' => 'neutral', 'disabled' => true];
    $email = trim((string) ($row['email'] ?? ''));
    $selectedSupplierIds = collect($selectedIds)->map(fn ($id) => (int) $id);
    $checked = $selectedSupplierIds->contains($supplierId);
@endphp

<tr wire:key="rfq-management-row-{{ $supplierId }}">
    <td class="ft-rfq-checkbox-col" data-label="Select">
        <input
            type="checkbox"
            class="ft-rfq-row-checkbox"
            wire:model.live="rfqSelectedSupplierIds"
            value="{{ $supplierId }}"
            @disabled(! ($row['selectable'] ?? false))
            aria-label="Select {{ $row['supplier_short_code'] ?? ($row['supplier_name'] ?? 'supplier') }}"
            @checked($checked)
        >
    </td>

    <td data-label="Supplier">
        <div class="ft-rfq-management-supplier">
            <span class="ft-rfq-management-avatar">{{ $row['initials'] ?? '—' }}</span>
            <strong title="{{ $row['supplier_name'] ?? 'Supplier' }}">{{ $row['supplier_short_code'] ?? ($row['supplier_name'] ?? 'Supplier') }}</strong>
        </div>
    </td>

    <td data-label="Email">
        @if($email !== '')
            <span class="ft-rfq-management-email" title="{{ $email }}">{{ $email }}</span>
        @else
            <span class="ft-rfq-management-email is-missing">No email configured</span>
        @endif
    </td>

    <td class="ft-rfq-email-status-col" data-label="Email status">
        <div class="ft-rfq-management-status-stack">
            <span class="ft-rfq-management-status is-{{ $row['email_tone'] ?? 'neutral' }}">
                @switch($row['email_status_key'] ?? '')
                    @case('sent')
                    @case('ready')
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="m8.5 12 2.3 2.3 4.7-5"></path></svg>
                        @break
                    @case('failed')
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M12 8.5v4.5M12 16h.01"></path></svg>
                        @break
                    @case('missing')
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3.5 21 19H3z"></path><path d="M12 9v4M12 16h.01"></path></svg>
                        @break
                    @case('queued')
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M12 7.5V12l3 2"></path></svg>
                        @break
                @endswitch
                <span>{{ $row['email_status_label'] ?? '—' }}</span>
            </span>
            @if(filled($row['email_status_detail'] ?? null))
                <small class="is-danger">{{ $row['email_status_detail'] }}</small>
            @endif
        </div>
    </td>

    <td data-label="RFQ status"><span class="ft-rfq-management-rfq-status">{{ $row['rfq_status_label'] ?? '—' }}</span></td>

    <td data-label="Last activity">
        <span class="ft-rfq-management-last-activity">
            @if($row['last_activity_at'] ?? null)
                {{ \App\Support\UserLocalTime::format($row['last_activity_at'], 'M j, Y') }} · {{ \App\Support\UserLocalTime::format($row['last_activity_at'], 'g:i A') }}
            @else
                —
            @endif
        </span>
    </td>

    <td class="ft-rfq-actions-col" data-label="Actions">
        @if(($action['type'] ?? '') === 'view_response')
            <button type="button" class="ft-rfq-row-action is-success" wire:click="setDetailTab('comparison')">View response</button>
        @elseif(($action['type'] ?? '') === 'setup_email')
            @if($canEditSupplier)
                <a href="{{ route('master-data', ['group' => 'supplier', 'edit_supplier' => $supplierId]) }}" wire:navigate class="ft-rfq-row-action is-warning">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.5-3.1 2.4-4.7 5.5-4.7 2.2 0 3.8.8 4.7 2.3M17 13v6M14 16h6"></path></svg>
                    <span>Set up email</span>
                </a>
            @else
                <button type="button" class="ft-rfq-row-action is-warning" disabled>Set up email</button>
            @endif
        @elseif(($action['type'] ?? '') === 'send' && $canManage)
            <button
                type="button"
                class="ft-rfq-row-action is-{{ $action['tone'] ?? 'success' }}"
                wire:click="sendRfqSupplierEmail({{ $supplierId }})"
                wire:loading.attr="disabled"
                wire:target="sendRfqSupplierEmail({{ $supplierId }})"
            >
                <span wire:loading.remove wire:target="sendRfqSupplierEmail({{ $supplierId }})" class="ft-rfq-row-action-content">
                    @if(($action['label'] ?? '') === 'Retry')
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18.5 8.5A7 7 0 1 0 19 15"></path><path d="M18.5 4v4.5H14"></path></svg>
                    @elseif(($action['label'] ?? '') === 'Resend')
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18.5 8.5A7 7 0 1 0 19 15"></path><path d="M18.5 4v4.5H14"></path></svg>
                    @else
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3.5" y="5.5" width="17" height="13" rx="2"></rect><path d="m5 7 7 5 7-5"></path></svg>
                    @endif
                    <span>{{ $action['label'] ?? 'Send email' }}</span>
                </span>
                <span wire:loading wire:target="sendRfqSupplierEmail({{ $supplierId }})" class="ft-rfq-row-action-content"><span class="ft-rfq-inline-spinner" aria-hidden="true"></span><span>Sending…</span></span>
            </button>
        @else
            <button type="button" class="ft-rfq-row-action is-neutral" disabled>
                @if(($row['email_status_key'] ?? '') === 'queued')<span class="ft-rfq-inline-spinner" aria-hidden="true"></span>@endif
                <span>{{ $action['label'] ?? 'Unavailable' }}</span>
            </button>
        @endif
    </td>
</tr>
