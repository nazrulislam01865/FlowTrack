@props([
    'primary' => '—',
    'secondary' => null,
])

<td class="ft-order-product-updated" data-label="Updated">
    <strong>{{ $primary }}</strong>
    @if(filled($secondary))
        <span>{{ $secondary }}</span>
    @endif
</td>
