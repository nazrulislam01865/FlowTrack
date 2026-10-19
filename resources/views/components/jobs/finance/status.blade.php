@props(['status'])
@php
    $key = strtolower((string) $status);
    $label = match($key) {
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'partial' => 'Partially paid',
        'draft' => 'Draft',
        'cancelled' => 'Cancelled',
        default => 'Sent',
    };
@endphp
<span class="ft-finance-status {{ $key }}">{{ $label }}</span>
