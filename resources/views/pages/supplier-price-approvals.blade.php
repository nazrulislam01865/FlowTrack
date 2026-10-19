@extends('layouts.app')
@section('content')
<style>
    /* Only page-specific layout and the existing native review dialog. Shared
       typography, surfaces, colors, badges, table and buttons come from x-ui. */
    .ft-price-approvals{min-width:0;width:100%;color:var(--ft-text-primary)}
    .ft-price-approvals .ft-price-panel{background:var(--ft-bg-surface);border:1px solid var(--ft-border-default);border-radius:var(--ft-radius-xl);overflow:hidden;box-shadow:var(--ft-shadow-xs)}
    .ft-price-approvals .ft-price-panel-heading{padding:20px 20px 16px;border-bottom:1px solid var(--ft-border-subtle)}
    .ft-price-approvals .ft-price-panel-heading .ft-section-header{margin:0}
    .ft-price-approvals .ft-price-panel-heading .ft-section-header__description{margin:5px 0 0}
    .ft-price-approvals .ft-price-panel .ft-table-shell{border:0;border-radius:0;box-shadow:none}
    .ft-price-approvals .ft-price-panel .ft-table{min-width:780px}
    .ft-price-approvals .ft-price-panel .ft-table th,.ft-price-approvals .ft-price-panel .ft-table td{padding:14px 16px}
    .ft-price-approvals .ft-price-panel .ft-table th:last-child,.ft-price-approvals .ft-price-panel .ft-table td:last-child{text-align:right}
    .ft-price-approvals .ft-product-name{font-weight:700;color:var(--ft-text-primary)}
    .ft-price-approvals .ft-subtext{font-size:var(--ft-font-size-xs);color:var(--ft-text-secondary);margin-top:4px}
    .ft-price-approvals .ft-price-tiers{line-height:1.7;font-variant-numeric:tabular-nums}
    .ft-price-approvals .ft-price-date{white-space:nowrap}
    .ft-price-approvals .ft-price-empty{padding:20px 16px 26px}
    .ft-price-approvals .ft-price-empty .ft-empty-state__icon{width:48px;height:48px}
    .ft-price-approvals .ft-page-links{padding:0 16px}
    .ft-price-approvals .ft-page-links:empty{display:none}
    .ft-price-approvals .ft-review-notice{padding:13px 15px;border:1px solid var(--ft-border-success);background:var(--ft-bg-success);color:var(--ft-text-success);margin-bottom:16px;border-radius:var(--ft-radius-md)}
    .ft-price-approvals .ft-review-notice.warning{border-color:var(--ft-border-warning);background:var(--ft-bg-warning);color:var(--ft-text-warning)}
    .ft-price-approvals dialog.ft-review-modal{border:0;border-radius:var(--ft-radius-xl);padding:0;width:min(690px,calc(100vw - 32px));max-height:calc(100dvh - 48px);overflow-y:auto;color:var(--ft-text-primary);background:var(--ft-bg-surface);box-shadow:0 28px 75px rgba(10,28,50,.23);margin:auto}
    .ft-price-approvals dialog.ft-review-modal::backdrop{background:var(--ft-overlay-backdrop)}
    .ft-price-approvals .ft-modal-header{padding:22px 25px 17px;border-bottom:1px solid var(--ft-border-default);display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
    .ft-price-approvals .ft-modal-header h3{font-size:var(--ft-font-size-xl);font-weight:700;margin:0 0 5px}
    .ft-price-approvals .ft-modal-close{border:1px solid var(--ft-border-default);background:var(--ft-bg-surface);color:var(--ft-text-primary);border-radius:var(--ft-radius-md);padding:6px 11px;font-size:20px;line-height:1;cursor:pointer}
    .ft-price-approvals .ft-modal-body{padding:20px 25px}
    .ft-price-approvals .ft-review-table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
    .ft-price-approvals .ft-review-table th,.ft-price-approvals .ft-review-table td{padding:12px;border-bottom:1px solid var(--ft-border-subtle);text-align:right}
    .ft-price-approvals .ft-review-table th:first-child,.ft-price-approvals .ft-review-table td:first-child{text-align:left}
    .ft-price-approvals .ft-review-table .ft-changed{color:var(--ft-text-success);font-weight:700;background:var(--ft-bg-success)}
    .ft-price-approvals .ft-removed{color:var(--ft-text-danger)}
    .ft-price-approvals .ft-review-label{font-size:var(--ft-font-size-sm);color:var(--ft-text-secondary);font-weight:700;margin:0 0 10px}
    .ft-price-approvals .ft-note-label{display:block;font-size:var(--ft-font-size-sm);font-weight:700;margin:20px 0 7px}
    .ft-price-approvals .ft-review-note{width:100%;padding:11px;border:1px solid var(--ft-border-strong);border-radius:var(--ft-radius-md);font:inherit;font-size:var(--ft-font-size-sm)}
    .ft-price-approvals .ft-modal-footer{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px;padding:15px 25px 23px}
    @media(max-width:650px){.ft-price-approvals .ft-price-panel-heading{padding:16px}.ft-price-approvals .ft-modal-body,.ft-price-approvals .ft-modal-header{padding:16px}.ft-price-approvals .ft-modal-footer{padding:14px 16px}}
</style>
<div class="ft-price-approvals">
    <x-ui.page-header title="Supplier Price Approvals" subtitle="Review supplier-requested price changes. Approved prices apply only to the selected supplier; customer selling prices stay the same." />

    @if(session('success'))<div role="status" class="ft-review-notice">{{ session('success') }}</div>@endif
    @if(session('warning'))<div role="status" class="ft-review-notice warning">{{ session('warning') }}</div>@endif

    <section class="ft-price-panel" aria-labelledby="ft-price-approvals-heading">
        <div class="ft-price-panel-heading">
            <x-ui.section-header id="ft-price-approvals-heading" title="Requests awaiting approval" description="Review the proposed quantity tiers before accepting or rejecting a request.">
                <x-slot:actions>
                    <x-ui.badge :label="number_format($proposals->total()).' pending'" :variant="$proposals->total() ? 'warning' : 'neutral'" :dot="$proposals->total() > 0" />
                </x-slot:actions>
            </x-ui.section-header>
        </div>
        @if($proposals->isEmpty())
            <x-ui.empty-state class="ft-price-empty" title="All caught up" description="No supplier price changes are awaiting approval. New requests will appear here when a supplier submits them.">
                <x-slot:icon><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></x-slot:icon>
            </x-ui.empty-state>
        @else
            <x-ui.table caption="Supplier price requests waiting for approval">
                <x-slot:head><tr><th scope="col">Product</th><th scope="col">Supplier</th><th scope="col">Proposed tiers</th><th scope="col">Requested</th><th scope="col">Status</th><th scope="col">Action</th></tr></x-slot:head>
                @foreach($proposals as $proposal)
                    <tr>
                        <td><div class="ft-product-name">{{ $proposal->product_name }}</div><div class="ft-subtext">{{ $proposal->product_display_code }}</div></td>
                        <td>{{ $proposal->supplier_name }}</td>
                        <td class="ft-price-tiers">
                            @foreach(array_slice($proposal->proposed_rows, 0, 3) as $tier)
                                <div>{{ number_format((int) $tier['quantity']) }} units → {{ rtrim(rtrim(number_format((float) $tier['price'], 6, '.', ','), '0'), '.') }}</div>
                            @endforeach
                            @if($proposal->new_tier_count > 3)<div class="ft-subtext">+{{ $proposal->new_tier_count - 3 }} more in details</div>@endif
                        </td>
                        <td class="ft-price-date">{{ \Illuminate\Support\Carbon::parse($proposal->created_at)->format('d M Y, h:i A') }}</td>
                        <td><x-ui.badge label="Pending" variant="warning" dot /></td>
                        <td><x-ui.button type="button" size="sm" data-open-price-review="{{ $proposal->id }}">View changes</x-ui.button></td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
        @if($proposals->hasPages())<div class="ft-page-links">{{ $proposals->links() }}</div>@endif
    </section>
    @foreach($proposals as $proposal)
        <dialog id="ft-price-review-{{ $proposal->id }}" class="ft-review-modal" aria-labelledby="ft-price-review-title-{{ $proposal->id }}">
            <div class="ft-modal-header">
                <div>
                    <h3 id="ft-price-review-title-{{ $proposal->id }}">Review supplier price change</h3>
                    <div class="ft-product-name">{{ $proposal->product_name }}</div>
                    <div class="ft-subtext">{{ $proposal->product_display_code }} · {{ $proposal->supplier_name }}</div>
                </div>
                <button type="button" class="ft-modal-close" data-close-price-review aria-label="Close price review">&times;</button>
            </div>
            <form method="post" action="{{ route('supplier-prices.review', $proposal->id) }}">
                @csrf
                <div class="ft-modal-body">
                    <p class="ft-review-label">Compare the current approved supplier unit prices with the requested prices at each quantity.</p>
                    <div style="overflow-x:auto">
                        <table class="ft-review-table">
                            <thead><tr><th>Quantity</th><th>Current unit price</th><th>Requested unit price</th></tr></thead>
                            <tbody>
                                @foreach($proposal->changes as $change)
                                    <tr>
                                        <td>{{ number_format($change['quantity']) }}</td>
                                        <td>{{ $change['old_price'] === null ? '—' : rtrim(rtrim(number_format((float) $change['old_price'], 6, '.', ','), '0'), '.') }}</td>
                                        <td class="{{ $change['changed'] ? 'ft-changed' : '' }} {{ $change['new_price'] === null ? 'ft-removed' : '' }}">
                                            {{ $change['new_price'] === null ? 'Removed' : rtrim(rtrim(number_format((float) $change['new_price'], 6, '.', ','), '0'), '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <label class="ft-note-label" for="ft-review-note-{{ $proposal->id }}">Decision note (optional)</label>
                    <textarea id="ft-review-note-{{ $proposal->id }}" class="ft-review-note" name="review_note" rows="2" maxlength="500" placeholder="Reason or instructions for the supplier"></textarea>
                </div>
                <div class="ft-modal-footer">
                    <x-ui.button variant="secondary" type="button" data-close-price-review>Cancel</x-ui.button>
                    <x-ui.button variant="danger" type="submit" name="decision" value="rejected">Reject</x-ui.button>
                    <x-ui.button type="submit" name="decision" value="approved">Approve changes</x-ui.button>
                </div>
            </form>
        </dialog>
    @endforeach
</div>
<script nonce="{{ request()->attributes->get('csp_nonce') }}">
document.addEventListener('click', function (event) {
    var open = event.target.closest('[data-open-price-review]');
    if (open) {
        var dialog = document.getElementById('ft-price-review-' + open.dataset.openPriceReview);
        if (dialog && !dialog.open) dialog.showModal();
        return;
    }
    var close = event.target.closest('[data-close-price-review]');
    if (close) close.closest('dialog')?.close();
});
</script>
@endsection
