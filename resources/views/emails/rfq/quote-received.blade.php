<x-email.rfq-frame :brand="$brand">
    <x-slot:footer>Submission reference Q-{{ str_pad((string) ($quote?->id ?? 0), 6, '0', STR_PAD_LEFT) }}</x-slot:footer>

    <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#11805d;margin-bottom:7px">Quotation received</div>
    <h1 style="margin:0 0 12px;font-size:23px;line-height:1.22;color:#152238;letter-spacing:-.3px">Thank you — we received your quotation</h1>
    <p style="margin:0 0 18px;color:#44566f;font-size:13px">Hello {{ $contact }}. Your quotation for <strong style="color:#152238">{{ $inquiry->inquiry_number }}</strong> was received{{ $quote?->updated_at ? ' on '.$quote->updated_at->format('M j, Y \a\t g:i A') : '' }}.</p>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 18px;background:#eef9f5;border:1px solid #cbe8dc;border-radius:9px"><tr><td style="padding:15px 16px">
        <div style="font-size:10px;color:#4d7468;text-transform:uppercase;letter-spacing:.05em">Submitted total</div>
        <div style="margin-top:3px;font-size:20px;font-weight:800;color:#11654c">{{ $quote?->currency ?? $inquiry->currency ?? 'USD' }} {{ number_format((float) ($quote?->submitted_total ?? 0), 2) }}</div>
        <div style="margin-top:5px;font-size:11px;color:#5d766e">{{ number_format((float) $items->sum('quantity'), 0) }} units{{ $quote?->lead_time_days ? ' · '.$quote->lead_time_days.'-day lead time' : '' }} · Freight {{ $quote?->currency ?? $inquiry->currency ?? 'USD' }} {{ number_format((float) ($quote?->freight ?? 0), 2) }}</div>
    </td></tr></table>

    <p style="margin:0;color:#44566f;font-size:13px">{{ $brand['name'] ?? 'Company' }} will contact you when a decision is made. Please keep this message for your records.</p>
</x-email.rfq-frame>
