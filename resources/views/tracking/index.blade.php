@extends('tracking.layout')

@section('title', 'Order tracking — STEP PROMO')

@section('content')
<main class="ft-tracking-shell">
    <section class="ft-tracking-search-pane" aria-labelledby="tracking-search-page-title">
        <h1 id="tracking-search-page-title">Order tracking</h1>
        <x-order-tracking.search-card
            :lookup-type="$lookupType ?? old('lookup_type', 'order')"
            :identifier="$identifier ?? ''"
            :email="$email ?? ''"
            :lookup-error="$lookupError ?? null"
        />
    </section>

    <section class="ft-tracking-result-pane" aria-labelledby="tracking-result-page-title">
        <h1 id="tracking-result-page-title">Order tracking</h1>
        @if(isset($tracking))
            <x-order-tracking.result-card :tracking="$tracking" />
        @else
            <div class="ft-tracking-result-placeholder" aria-hidden="true"></div>
        @endif
    </section>
</main>
@endsection
