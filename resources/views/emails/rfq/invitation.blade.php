<x-email.rfq-frame :brand="$brand">
    <x-slot:footer>Quotation request {{ $inquiry->inquiry_number }} · Sent automatically from {{ $brand['name'] ?? 'Company' }}</x-slot:footer>

    <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#007d70;margin-bottom:7px">Request for quotation</div>
    <h1 style="margin:0 0 12px;font-size:23px;line-height:1.22;color:#152238;letter-spacing:-.3px">You're invited to submit a quotation</h1>
    <p style="margin:0 0 14px;color:#44566f;font-size:13px">Hello {{ $contact }},</p>
    <p style="margin:0 0 20px;color:#44566f;font-size:13px">{{ $brand['name'] ?? 'Company' }} is requesting a quotation from <strong style="color:#152238">{{ $supplier->name }}</strong> for {{ $items->count() === 1 ? 'the product below' : 'the products below' }}.</p>

    @if(filled($requestMessage ?? null))
        <div style="margin:0 0 14px;padding:12px 14px;border-left:3px solid #0b8f80;background:#f1faf8;border-radius:7px;color:#385d58;font-size:12px;line-height:1.55">
            <div style="margin-bottom:4px;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#08776c">Special note from buyer</div>
            {!! nl2br(e($requestMessage)) !!}
        </div>
    @endif

    @if(filled($supplierDetails ?? null))
        <div style="margin:0 0 18px;padding:12px 14px;background:#f7f9fc;border:1px solid #e1e7ef;border-radius:7px;color:#44566f;font-size:12px;line-height:1.55">
            <div style="margin-bottom:4px;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#718097">Inquiry &amp; product details</div>
            {!! nl2br(e($supplierDetails)) !!}
        </div>
    @endif

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 16px;background:#f8fafc;border:1px solid #e1e7ef;border-radius:9px"><tr>
        <td style="padding:12px 14px"><div style="font-size:10px;color:#718097;text-transform:uppercase;letter-spacing:.05em">Inquiry</div><div style="margin-top:3px;font-size:13px;font-weight:700;color:#152238">{{ $inquiry->inquiry_number }}</div></td>
        <td style="padding:12px 14px;border-left:1px solid #e1e7ef"><div style="font-size:10px;color:#718097;text-transform:uppercase;letter-spacing:.05em">Quotation due</div><div style="margin-top:3px;font-size:13px;font-weight:700;color:#152238">{{ $due?->format('M j, Y') ?? 'No due date' }}</div></td>
    </tr></table>

    @foreach($items as $item)
        <x-email.rfq-detail
            :title="$item->item_name"
            :meta="number_format((float) $item->quantity, 0).' '.($item->unit ?: 'units').' · '.($item->category ?: 'Product')"
        />
    @endforeach

    <x-email.rfq-button :href="$publicUrl">View request &amp; submit quotation</x-email.rfq-button>
    <p style="margin:0;color:#718097;font-size:11px;line-height:1.55">
        This secure link is unique to your company. No account is required.
        @if(!empty($linkExpiresAt)) It remains valid until <strong style="color:#52647c">{{ $linkExpiresAt->format('M j, Y 	 g:i A') }}</strong>. @endif
    </p>
</x-email.rfq-frame>
