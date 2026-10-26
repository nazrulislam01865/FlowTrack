@extends('tracking.layout')

@section('title', 'Select Your Order — STEP PROMO')

@section('content')
<div class="ft-lookup-layout" style="max-width:760px;margin:0 auto;display:block;">
    <div class="ft-lookup-card">
        <h1 class="ft-lookup-title">Matching orders found</h1>
        <p class="ft-lookup-intro">Multiple orders matched your search. Select the order you wish to track:</p>

        <div class="ft-disambiguate-list">
            @foreach ($orders as $order)
                <a href="{{ route('order.track.show', ['token' => $order->ensureTrackingToken()]) }}" class="ft-disambiguate-item">
                    <div>
                        <div style="font-weight:750;font-size:15px;color:#0f172a;margin-bottom:2px;">
                            {{ $order->displayOrderNumber() ?: ($order->job_number ?: $order->order_number) }}
                        </div>
                        <div style="font-size:12.5px;color:#64748b;">
                            Reference: {{ $order->reference_number ?: ($order->job_number ?: $order->order_number) }} · Placed on {{ $order->created_at ? $order->created_at->format('M d, Y') : 'Recent' }}
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;color:#007a64;font-size:13px;font-weight:600;">
                        <span>Track order</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                    </div>
                </a>
            @endforeach
        </div>

        <div style="margin-top:20px;text-align:center;">
            <a href="{{ route('order.track') }}" class="ft-track-another-link">&larr; Back to search</a>
        </div>
    </div>
</div>
@endsection
