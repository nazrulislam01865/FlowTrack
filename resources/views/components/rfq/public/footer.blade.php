@props(['brand', 'buyerEmail' => ''])
<footer class="ft-rfq-portal-footer">
    <div>Powered by <strong>{{ $brand['name'] ?? 'FlowTrack' }}</strong></div>
    <nav aria-label="Supplier portal links">
        <span>Privacy</span>
        <span>Terms</span>
        @if($buyerEmail)<a href="mailto:{{ $buyerEmail }}">Contact buyer</a>@else<span>Contact buyer</span>@endif
    </nav>
</footer>
