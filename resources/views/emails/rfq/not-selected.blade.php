<x-email.rfq-frame :brand="$brand">
    <x-slot:footer>No pricing or selected-supplier details are disclosed in this message.</x-slot:footer>

    <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#61738d;margin-bottom:7px">Quotation update</div>
    <h1 style="margin:0 0 12px;font-size:23px;line-height:1.22;color:#152238;letter-spacing:-.3px">Thank you for your quotation</h1>
    <p style="margin:0 0 14px;color:#44566f;font-size:13px">Hello {{ $contact }},</p>
    <p style="margin:0 0 16px;color:#44566f;font-size:13px">Thank you for the time you invested in <strong style="color:#152238">{{ $inquiry->inquiry_number }}</strong>. {{ $brand['name'] ?? 'Company' }} has selected another quotation for this inquiry.</p>
    <x-email.rfq-detail :title="$inquiry->subject" :meta="number_format((float) $items->sum('quantity'), 0).' total units'" />
    <p style="margin:18px 0 0;color:#44566f;font-size:13px">We appreciate your support and look forward to inviting {{ $supplier->name }} to future opportunities.</p>
</x-email.rfq-frame>
