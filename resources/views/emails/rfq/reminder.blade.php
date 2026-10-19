<x-email.rfq-frame :brand="$brand">
    <x-slot:footer>If you cannot quote this request, open the secure request and choose “Decline”.</x-slot:footer>

    <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#b86a00;margin-bottom:7px">Due-date reminder</div>
    <h1 style="margin:0 0 12px;font-size:23px;line-height:1.22;color:#152238;letter-spacing:-.3px">Quotation deadline reminder</h1>
    <p style="margin:0 0 16px;color:#44566f;font-size:13px">Hello {{ $contact }}, this is a friendly reminder that your quotation for <strong style="color:#152238">{{ $inquiry->inquiry_number }}</strong> is due <strong style="color:#152238">{{ $due?->format('M j, Y \a\t g:i A') }}</strong>.</p>

    <x-email.rfq-detail
        :title="$inquiry->subject"
        :meta="number_format((float) $items->sum('quantity'), 0).' total units · '.($items->count()).' '.($items->count() === 1 ? 'product' : 'products')"
    />

    <x-email.rfq-button :href="$publicUrl">Continue quotation</x-email.rfq-button>
    <p style="margin:0;color:#718097;font-size:11px;line-height:1.55">If you have already submitted, no further action is required.@if(!empty($linkExpiresAt)) This secure link remains valid until {{ $linkExpiresAt->format('M j, Y \a\t g:i A') }}.@endif</p>
</x-email.rfq-frame>
