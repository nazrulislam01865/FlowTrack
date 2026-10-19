@extends('layouts.app')
@section('content')
@php
    $routeSensitiveOrderMode = request()->hasAny(['open', 'task', 'create']);
    $orderListHasInitialContext = request()->hasAny([
        'search', 'client', 'phase', 'owner', 'metric', 'date_from', 'date_to', 'import',
    ]);
@endphp

@if($routeSensitiveOrderMode)
    {{-- Create/detail mode depends on the original route query string in mount().
         It must mount in the initial GET; a deferred mount runs later through
         /livewire/update where request('create/open/task') is no longer present. --}}
    <livewire:jobs.index />
@elseif($orderListHasInitialContext)
    {{-- Deep-linked list filters/import results also read request() during mount(). --}}
    <livewire:orders.index />
@else
    {{-- Plain list mode has no route context to preserve and is safe to defer. --}}
    <livewire:orders.index defer />
@endif
@if(config('order_baseline.enabled') && request()->integer('open') > 0)
    {{-- Only on a benchmarked detail GET; read-only browser timing collector. --}}
    <script src="{{ asset('js/order-baseline.js') }}" defer></script>
@endif
@endsection
