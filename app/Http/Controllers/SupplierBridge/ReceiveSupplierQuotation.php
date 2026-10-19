<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\InquiryRfqQuote;
use App\Models\InquiryRfqInvitation;
use App\Services\Inquiries\InquiryRfqService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Finalize the buyer's existing RFQ quote and its private documents in one transaction. */
final class ReceiveSupplierQuotation extends Controller
{
    use VerifySupplierQuotation;

    public function __invoke(Request $request, InquiryRfqService $rfq)
    {
        $input = $this->signedQuotation($request);
        $data = Validator::make($input,[
            'supplier_id'=>['required','integer','min:1'],
            'invitation_id'=>['required','integer','min:1'],
            'submission_uuid'=>['required','uuid'],
            'total_minor_units'=>['required','integer','min:0'],
            'quotation'=>['required','array'],
            'quotation.contact'=>['required','string','max:120'],
            'quotation.email'=>['required','email','max:254'],
            'quotation.phone'=>['required','string','max:60'],
            'quotation.currency'=>['required',Rule::in(['USD','EUR','CNY','GBP','BDT'])],
            'quotation.unit_price'=>['nullable','numeric','gt:0','max:100000000'],
            'quotation.moq'=>['nullable','integer','min:1','max:100000000'],
            'quotation.item_prices'=>['nullable','array','max:100'],
            'quotation.item_prices.*.item_id'=>['required','integer','min:1','distinct'],
            'quotation.item_prices.*.unit_price'=>['required','numeric','gt:0','max:100000000'],
            'quotation.item_prices.*.moq'=>['required','integer','min:1','max:100000000'],
            'quotation.tooling'=>['required','numeric','min:0','max:100000000'],
            'quotation.sample'=>['required','numeric','min:0','max:100000000'],
            'quotation.discount'=>['required','numeric','min:0','max:100000000'],
            'quotation.tax'=>['required',Rule::in(['Included','Excluded'])],
            'quotation.production_lead'=>['required','integer','min:1','max:3650'],
            'quotation.sample_lead'=>['required','integer','min:1','max:3650'],
            'quotation.incoterm'=>['required',Rule::in(['FOB','CIF','EXW','DDP'])],
            'quotation.shipping_port'=>['required','string','max:150'],
            'quotation.delivery_date'=>['required','date_format:Y-m-d'],
            'quotation.validity'=>['required','integer','min:1','max:365'],
            'quotation.compliance'=>['required',Rule::in(['Yes, fully compliant','Partially compliant','No - alternative proposed'])],
            'quotation.deviations'=>['nullable','string','max:4000'],
            'quotation.notes'=>['nullable','string','max:4000'],
            'quotation.document_notes'=>['nullable','string','max:4000'],
            'quotation.supporting_information'=>['nullable','array','max:3'],
            'quotation.supporting_information.*'=>[Rule::in(['Product certification','Factory audit report','Sample images'])],
            'quotation.price_breaks'=>['nullable','array','max:0'],
            'documents'=>['present','array','max:10'],
            'documents.*.id'=>['required','integer','min:1','distinct'],
            'documents.*.name'=>['required','string','max:255'],
            'documents.*.document_type'=>['required',Rule::in(['Formal quotation','Price breakdown','Product specification','Supporting document'])],
            'documents.*.size'=>['required','integer','min:1','max:20971520'],
            'documents.*.sha256'=>['required','regex:/^[a-f0-9]{64}$/'],
        ])->validate();
        $invitation = $this->authorizeInvitation((int)$data['invitation_id'],(int)$data['supplier_id']);
        return DB::transaction(function () use ($invitation,$data,$rfq) {
            $locked = InquiryRfqInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            $existing = InquiryRfqQuote::query()->where('invitation_id',$locked->id)->first();
            if ($locked->quote_status === 'submitted') {
                abort_unless($existing && $existing->portal_submission_uuid === $data['submission_uuid'],409,'A different quotation is already submitted.');
                return response()->json(['received'=>true,'quote_id'=>$existing->id,'duplicate'=>true]);
            }
            abort_if($locked->due_at?->isPast() || $locked->awarded_at || $locked->rejected_at,409,'RFQ is closed.');
            $source = $locked->inquiry()->with('items:id,inquiry_id,item_name,quantity,sort_order')->firstOrFail();
            $scopedIds = $locked->scopedItemIds();
            $items = $scopedIds === [] ? $source->items : $source->items->whereIn('id', $scopedIds)->values();
            abort_if($items->isEmpty() || $items->count() > 100,422,'RFQ products are unavailable.');
            $values = $data['quotation'];
            if ($items->count() === 1) {
                abort_unless(isset($values['unit_price'],$values['moq']),422,'Missing unit price or MOQ.');
                $lines = [['inquiry_item_id'=>$items->first()->id,'unit_price'=>$values['unit_price'],'moq'=>$values['moq']]];
            } else {
                $prices = collect($values['item_prices'] ?? [])->keyBy('item_id');
                $ids = $items->pluck('id')->map(fn ($id)=>(int)$id)->sort()->values()->all();
                $quoted = $prices->keys()->map(fn ($id)=>(int)$id)->sort()->values()->all();
                abort_unless($ids === $quoted,422,'A quotation price is required for each RFQ item.');
                $lines = $items->map(fn ($item)=>[
                    'inquiry_item_id'=>$item->id,
                    'unit_price'=>$prices[$item->id]['unit_price'],
                    'moq'=>$prices[$item->id]['moq'],
                ])->all();
            }
            $inbox = DB::table('supplier_quotation_inbox_files')->where('invitation_id',$locked->id)
                ->whereIn('external_document_id',collect($data['documents'])->pluck('id')->all())->get()->keyBy('external_document_id');
            abort_unless($inbox->count() === count($data['documents']),422,'A quotation attachment has not yet arrived.');
            foreach ($data['documents'] as $doc) {
                $stored = $inbox->get((int)$doc['id']);
                abort_unless($stored && hash_equals($stored->sha256,$doc['sha256']) && (int)$stored->size === $doc['size'],422,'Quotation attachment mismatch.');
            }
            // Check the total before calling submitQuote (which can emit confirmation email).
            $subtotal = 0.0;
            foreach ($lines as $line) {
                $sourceItem = $items->firstWhere('id',(int)$line['inquiry_item_id']);
                $subtotal += (float)$sourceItem->quantity * (float)$line['unit_price'];
            }
            $expectedTotal = (int) round(round($subtotal + (float)$values['tooling'] + (float)$values['sample'] - (float)$values['discount'], 2) * 100);
            abort_unless($expectedTotal >= 0 && abs($expectedTotal - $data['total_minor_units']) <= 1,422,'Quoted totals do not match.');
            $buyerData = [
                'supplier_contact_name'=>$values['contact'],
                'supplier_contact_email'=>$values['email'],
                'supplier_contact_phone'=>$values['phone'],
                'currency'=>$values['currency'],
                'freight'=>0,
                'tooling_cost'=>$values['tooling'],
                'sample_cost'=>$values['sample'],
                'discount'=>$values['discount'],
                'tax_status'=>$values['tax'] === 'Included' ? 'included' : 'excluded',
                'lead_time_days'=>$values['production_lead'],
                'sample_lead_time_days'=>$values['sample_lead'],
                'incoterm'=>$values['incoterm'],
                'shipping_port'=>$values['shipping_port'],
                'estimated_delivery_date'=>$values['delivery_date'],
                'validity_days'=>$values['validity'],
                'specification_compliance'=>match($values['compliance']) {
                    'Yes, fully compliant'=>'yes','Partially compliant'=>'partial',default=>'no'
                },
                'notes'=>trim(($values['notes'] ?? '').(filled($values['deviations'] ?? null) ? "\nDeviations: ".$values['deviations'] : '')),
                'document_notes'=>$values['document_notes'] ?? null,
                'supporting_information'=>array_values(array_map(static fn ($v) => match ($v) {
                    'Product certification'=>'product_certification', 'Factory audit report'=>'factory_audit_report',
                    'Sample images'=>'sample_images', default=>'' }, $values['supporting_information'] ?? [])),
                'submitted_by_name'=>$values['contact'],
                'submitted_by_email'=>$values['email'],
            ];
            $quote = $rfq->submitQuote($locked, $lines, $buyerData, function (InquiryRfqQuote $quote) use ($data,$inbox): void {
                $quote->update(['portal_submission_uuid'=>$data['submission_uuid'], 'portal_details'=>$data['quotation']]);
                foreach ($data['documents'] as $index=>$doc) {
                    $staged = $inbox->get((int)$doc['id']);
                    $quote->documents()->create([
                        'document_type'=>match($doc['document_type']) {
                            'Formal quotation'=>'formal_quotation','Price breakdown'=>'price_breakdown',
                            'Product specification'=>'product_specification',default=>'other',
                        },
                        'name'=>basename($doc['name']),
                        'path'=>$staged->path,
                        'mime_type'=>$staged->mime_type,
                        'size'=>$doc['size'], 'sort_order'=>$index+1,
                    ]);
                }
            });
            return response()->json(['received'=>true,'quote_id'=>$quote->id]);
        },3);
    }
}
