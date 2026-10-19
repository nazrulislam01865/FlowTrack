@extends('tracking.layout')

@section('title', 'Order ' . $tracking['order_number'] . ' — Tracking')

@section('content')
<main class="ft-tracking-shell">
    <section class="ft-tracking-search-pane" aria-labelledby="tracking-search-page-title">
        <h1 id="tracking-search-page-title">Order tracking</h1>
        <x-order-tracking.search-card
            lookup-type="order"
            :identifier="$tracking['order_number'] ?? ''"
            email=""
            :lookup-error="null"
        />
    </section>

    <section class="ft-tracking-result-pane" aria-labelledby="tracking-result-page-title">
        <h1 id="tracking-result-page-title">Order tracking</h1>
        <x-order-tracking.result-card :tracking="$tracking" />
    </section>
</main>
@endsection
