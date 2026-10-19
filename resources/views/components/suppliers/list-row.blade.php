@props([
    'supplier',
    'products' => collect(),
    'displayTimezone' => null,
    'canEdit' => false,
])

@php
    $products = collect($products);
    $name = trim((string) $supplier->name);
    $initials = collect(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
    $contact = trim((string) data_get($supplier->metadata, 'contact_person'));
    $email = trim((string) data_get($supplier->metadata, 'email'));
    $updatedAt = $supplier->updated_at;
    if ($updatedAt && $displayTimezone) $updatedAt = $updatedAt->copy()->timezone($displayTimezone);
@endphp

<tr>
    <td>
        <div class="ft-supplier-list-entity">
            <span class="ft-supplier-list-avatar" aria-hidden="true">{{ $initials ?: 'S' }}</span>
            <span class="ft-supplier-list-entity-copy">
                <a href="{{ route('master-data', ['group' => 'supplier', 'supplier' => $supplier->id]) }}" wire:navigate>{{ $supplier->name }}</a>
                <small>{{ number_format($products->count()) }} product{{ $products->count() === 1 ? '' : 's' }}</small>
            </span>
        </div>
    </td>
    <td>
        <div class="ft-supplier-list-contact">
            <strong>{{ $contact !== '' ? $contact : '—' }}</strong>
            <small>{{ $email !== '' ? $email : 'No email' }}</small>
        </div>
    </td>
    <td><x-suppliers.product-tags :products="$products" /></td>
    <td><x-suppliers.status-badge :status="$supplier->status" /></td>
    <td class="ft-supplier-list-updated">{{ $updatedAt?->format('M j, Y') ?? '—' }}</td>
    <td class="ft-supplier-list-row-action">
        <div class="ft-supplier-list-row-actions">
            <a
                href="{{ route('master-data', ['group' => 'supplier', 'supplier' => $supplier->id]) }}"
                wire:navigate
                class="ft-supplier-list-link"
            >View</a>
            @if($canEdit)
                <a
                    href="{{ route('master-data', ['group' => 'supplier', 'edit_supplier' => $supplier->id]) }}"
                    wire:navigate
                    class="ft-supplier-list-link"
                >Edit</a>
            @endif
        </div>
    </td>
</tr>
