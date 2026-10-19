@props([
    'label' => null,
    'variant' => null,
    'dynamicColor' => null,
    'dot' => false,
])

<span
    {{ $attributes->class([
        'ft-badge',
        'ft-badge--neutral' => $variant === 'neutral',
        'ft-badge--info' => $variant === 'info' || ($variant === null && preg_match('/In Progress|Submitted|Ready|Transit|Artwork|Shipment|Invoice/i', (string) $label) === 1),
        'ft-badge--success' => $variant === 'success' || ($variant === null && preg_match('/Completed|Approved|Paid|Delivered|On Track|Active/i', (string) $label) === 1),
        'ft-badge--warning' => $variant === 'warning' || ($variant === null && preg_match('/Waiting|Negotiation|Partially|Needs Attention/i', (string) $label) === 1),
        'ft-badge--danger' => $variant === 'danger' || ($variant === null && preg_match('/Blocked|Critical|Overdue|Revision|Delayed|At Risk/i', (string) $label) === 1),
        'ft-badge--purple' => $variant === 'purple',
        'ft-badge--dynamic' => filled($dynamicColor),
        'badge' => $variant === null,
        'b-green' => $variant === null && preg_match('/Completed|Approved|Paid|Delivered|On Track|Active/i', (string) $label) === 1,
        'b-red' => $variant === null && preg_match('/Blocked|Critical|Overdue|Revision|Delayed|At Risk/i', (string) $label) === 1,
        'b-amber' => $variant === null && preg_match('/Waiting|Negotiation|Partially|Needs Attention/i', (string) $label) === 1,
        'b-blue' => $variant === null && preg_match('/In Progress|Submitted|Ready|Transit|Artwork|Shipment|Invoice/i', (string) $label) === 1,
        'b-gray' => $variant === null && preg_match('/Completed|Approved|Paid|Delivered|On Track|Active|Blocked|Critical|Overdue|Revision|Delayed|At Risk|Waiting|Negotiation|Partially|Needs Attention|In Progress|Submitted|Ready|Transit|Artwork|Shipment|Invoice/i', (string) $label) !== 1,
    ])->merge(filled($dynamicColor) ? ['style' => \App\Support\MasterColor::style($dynamicColor)] : []) }}
    @if($variant !== null || filled($dynamicColor)) data-ft-ui-component="badge" @endif
>
    @if($dot)<span class="ft-badge__dot" aria-hidden="true"></span>@endif
    {{ $label ?? $slot }}
</span>
