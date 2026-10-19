@props([
    'caption' => null,
])

<div class="ft-table-shell" data-ft-ui-component="table-shell">
    <table {{ $attributes->class(['ft-table']) }} data-ft-ui-component="table">
        @if(filled($caption))<caption class="u-sr-only">{{ $caption }}</caption>@endif
        @if(isset($head))<thead>{{ $head }}</thead>@endif
        <tbody>{{ $slot }}</tbody>
        @if(isset($foot))<tfoot>{{ $foot }}</tfoot>@endif
    </table>
</div>
