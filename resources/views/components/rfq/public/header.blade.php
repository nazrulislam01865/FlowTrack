@props(['brand', 'supplier', 'buyerEmail' => ''])
<header class="ft-rfq-portal-topbar">
    <div class="ft-rfq-portal-topbar__left">
        <a class="ft-rfq-portal-brand" href="#" aria-label="{{ $brand['name'] ?? 'FlowTrack' }}">
            @if($brand['logo_url'] ?? null)
                <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] ?? 'FlowTrack' }}">
            @else
                <span class="ft-rfq-portal-brandmark" aria-hidden="true"><i></i><i></i></span>
                <strong>{{ $brand['name'] ?? 'FlowTrack' }}</strong>
            @endif
        </a>
        <span class="ft-rfq-portal-topbar__divider"></span>
        <span class="ft-rfq-portal-secure-title"><x-rfq.public.icon name="lock" /> Secure supplier portal</span>
    </div>
    <div class="ft-rfq-portal-topbar__right">
        @if($buyerEmail)
            <a href="mailto:{{ $buyerEmail }}" class="ft-rfq-portal-help"><x-rfq.public.icon name="help" /> Need help?</a>
        @else
            <span class="ft-rfq-portal-help"><x-rfq.public.icon name="help" /> Need help?</span>
        @endif
        <span class="ft-rfq-portal-topbar__divider"></span>
        <span class="ft-rfq-portal-supplier-name">{{ $supplier?->name ?: 'Supplier' }} <x-rfq.public.icon name="chevron-down" /></span>
    </div>
</header>
