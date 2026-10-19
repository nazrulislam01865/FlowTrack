<x-email.rfq-frame :brand="$brand">
    <x-slot:footer>Awarded by {{ $awardedBy }} · {{ $brand['name'] ?? 'Company' }} · {{ now()->format('M j, Y') }}</x-slot:footer>

    <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#11805d;margin-bottom:7px">Supplier award</div>
    <h1 style="margin:0 0 12px;font-size:23px;line-height:1.22;color:#152238;letter-spacing:-.3px">Your quotation has been selected</h1>
    <p style="margin:0 0 14px;color:#44566f;font-size:13px">Hello {{ $contact }},</p>
    <p style="margin:0 0 18px;color:#44566f;font-size:13px">Congratulations. {{ $brand['name'] ?? 'Company' }} has selected <strong style="color:#152238">{{ $supplier->name }}</strong> for <strong style="color:#152238">{{ $inquiry->inquiry_number }}</strong>.</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 18px;background:#eef9f5;border:1px solid #cbe8dc;border-radius:9px"><tr><td style="padding:15px 16px">
        <div style="font-size:13px;font-weight:700;color:#152238">{{ $inquiry->subject }}</div>
        <div style="margin-top:5px;font-size:11px;color:#5d766e">{{ number_format((float) $items->sum('quantity'), 0) }} units · Awarded total {{ $quote?->currency ?? $inquiry->currency ?? 'USD' }} {{ number_format((float) ($quote?->submitted_total ?? 0), 2) }}{{ $quote?->lead_time_days ? ' · '.$quote->lead_time_days.'-day lead time' : '' }}</div>
    </td></tr></table>

    <x-email.rfq-button :href="$publicUrl ?? '#'">Review award details</x-email.rfq-button>
    <p style="margin:0;color:#44566f;font-size:13px">Our team will contact you with the purchase order and next steps.</p>
</x-email.rfq-frame>
