<x-email.order-frame
    :brand="$brand"
    :label="$handoffType === 'invoice' ? 'Client invoice' : 'Order workflow'"
    :footer-note="$handoffType === 'invoice' ? 'Invoice delivery from '.($brand['name'] ?? 'Company').'.' : null"
>
    <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#007d70;margin-bottom:7px">{{ $handoffType === 'invoice' ? 'Invoice' : ($handoffType === 'purchase_order' ? 'Purchase Order handoff' : 'Artwork handoff') }}</div>
    <h1 style="margin:0 0 12px;font-size:23px;line-height:1.22;color:#152238;letter-spacing:-.3px">{{ $handoffType === 'invoice' ? 'Invoice '.$invoice->invoice_number : ($handoffType === 'purchase_order' ? 'Purchase Order ready for Artwork' : 'Artwork ready for Order Team') }}</h1>
    <p style="margin:0 0 14px;color:#44566f;font-size:13px">Hello {{ $team }},</p>
    <p style="margin:0 0 20px;color:#44566f;font-size:13px">
        @if($handoffType === 'invoice')
            Please find attached invoice <strong>{{ $invoice->invoice_number }}</strong> for Order <strong>{{ $orderNumber }}</strong>.
        @elseif($handoffType === 'purchase_order')
            The Purchase Order for <strong style="color:#152238">{{ $orderNumber }}</strong> has been uploaded and is attached for the Artwork Team to continue the order workflow.
        @else
            The latest confirmed artwork for <strong style="color:#152238">{{ $orderNumber }}</strong> is attached for the Order Team to continue with the client ERP / approval step.
        @endif
    </p>

    @if($handoffType === 'artwork' && filled($customerComment ?? ''))
        <div style="margin:0 0 18px;padding:12px 14px;border:1px solid #dce5ee;border-radius:9px;background:#f8fafc">
            <div style="margin-bottom:5px;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#718097">Comment to customer</div>
            <div style="white-space:pre-line;color:#44566f;font-size:13px;line-height:1.55">{{ $customerComment }}</div>
        </div>
    @endif

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 16px;background:#f8fafc;border:1px solid #e1e7ef;border-radius:9px">
        <tr>
            <td style="padding:12px 14px"><div style="font-size:10px;color:#718097;text-transform:uppercase;letter-spacing:.05em">Order</div><div style="margin-top:3px;font-size:13px;font-weight:700;color:#152238">{{ $orderNumber }}</div></td>
            <td style="padding:12px 14px;border-left:1px solid #e1e7ef"><div style="font-size:10px;color:#718097;text-transform:uppercase;letter-spacing:.05em">Client</div><div style="margin-top:3px;font-size:13px;font-weight:700;color:#152238">{{ $job->client?->name ?: 'Client' }}</div></td>
        </tr>
        <tr>
            <td style="padding:12px 14px;border-top:1px solid #e1e7ef"><div style="font-size:10px;color:#718097;text-transform:uppercase;letter-spacing:.05em">{{ $handoffType === 'invoice' ? 'Amount due' : 'Products' }}</div><div style="margin-top:3px;font-size:13px;font-weight:700;color:#152238">{{ $handoffType === 'invoice' ? $invoice->currency.' '.number_format((float) $invoice->total, 2) : $productSummary }}</div></td>
            <td style="padding:12px 14px;border-left:1px solid #e1e7ef;border-top:1px solid #e1e7ef"><div style="font-size:10px;color:#718097;text-transform:uppercase;letter-spacing:.05em">{{ $handoffType === 'invoice' ? 'Due date' : 'Sent by' }}</div><div style="margin-top:3px;font-size:13px;font-weight:700;color:#152238">{{ $handoffType === 'invoice' ? ($invoice->due_date?->format('M j, Y') ?: 'As agreed') : $sentBy->name }}</div></td>
        </tr>
    </table>

    <div style="padding:14px 16px;border:1px solid #dce5ee;border-radius:9px;background:#fff">
        <div style="font-size:10px;color:#718097;text-transform:uppercase;letter-spacing:.05em">{{ $handoffType === 'invoice' ? 'Attached invoice' : 'Attached file'.($documents->count() === 1 ? '' : 's') }}</div>
        @foreach($documents as $attachedDocument)
            <div style="margin-top:4px;font-size:13px;font-weight:700;color:#152238">{{ $attachedDocument->name }}</div>
        @endforeach
        @if($handoffType === 'invoice' || (int) ($document->version ?? 0) > 0)
            <div style="margin-top:2px;font-size:11px;color:#718097">{{ $handoffType === 'invoice' ? 'PDF · Invoice total '.$invoice->currency.' '.number_format((float) $invoice->total, 2) : 'Artwork version '.$document->version }}</div>
        @endif
    </div>

    <p style="margin:20px 0 0;color:#718097;font-size:11px;line-height:1.55">
        @if($handoffType === 'invoice' && filled($invoice->notes))
            {{ $invoice->notes }}<br><br>
        @endif
        {{ $handoffType === 'invoice' ? 'Please use invoice number '.$invoice->invoice_number.' as the payment reference. Reply to this email if you need any clarification.' : 'Reply to this email if you need clarification from '.$sentBy->name.'.' }}
    </p>
</x-email.order-frame>
