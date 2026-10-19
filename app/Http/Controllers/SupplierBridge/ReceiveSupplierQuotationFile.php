<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Services\SecureDocumentStorage;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class ReceiveSupplierQuotationFile extends Controller
{
    use VerifySupplierQuotation;

    public function __invoke(Request $request, SecureDocumentStorage $storage)
    {
        $secret = $this->bridgeSecret();
        $time = $request->header('X-Supplier-Timestamp');
        $supplier = $request->header('X-Supplier-Id');
        $invitation = $request->header('X-Supplier-Invitation');
        $document = $request->header('X-Supplier-Document');
        $digest = $request->header('X-Supplier-Digest');
        $signature = $request->header('X-Supplier-Signature');
        abort_unless($secret && is_string($time) && ctype_digit($time) && abs(time()-(int)$time) <= 300 &&
            is_string($supplier) && ctype_digit($supplier) && (int)$supplier > 0 &&
            is_string($invitation) && ctype_digit($invitation) && (int)$invitation > 0 &&
            is_string($document) && ctype_digit($document) && (int)$document > 0 &&
            is_string($digest) && preg_match('/^[a-f0-9]{64}$/', $digest) &&
            is_string($signature) && preg_match('/^[a-f0-9]{64}$/',$signature) &&
            hash_equals(hash_hmac('sha256',implode("\n",[$time,$supplier,$invitation,$document,$digest]),$secret),$signature),401);
        $invitationRecord = $this->authorizeInvitation((int)$invitation,(int)$supplier);
        $upload = $request->validate(['file'=>['required','file','max:20480','mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp,gif,zip']])['file'];
        abort_unless(hash_equals($digest, hash_file('sha256',$upload->getRealPath())),422,'Attachment checksum mismatch.');
        $existing = DB::table('supplier_quotation_inbox_files')->where('invitation_id',$invitationRecord->id)
            ->where('external_document_id',(int)$document)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->sha256,$digest) && (int)$existing->size === (int)$upload->getSize(),409,'Attachment identifier already used.');
            return response()->json(['received'=>true]);
        }
        abort_if($invitationRecord->quote_status === 'submitted' || $invitationRecord->due_at?->isPast(),409,'RFQ is closed.');
        abort_if(DB::table('supplier_quotation_inbox_files')->where('invitation_id',$invitationRecord->id)->count() >= 10,422,'Maximum ten attachments.');
        $stored = $storage->store($upload,'flowtrack/rfq-portal-inbox/'.$invitationRecord->id,20971520);
        try {
            DB::table('supplier_quotation_inbox_files')->insert([
                'invitation_id'=>$invitationRecord->id,'external_document_id'=>(int)$document,
                'path'=>$stored['path'],'mime_type'=>$stored['mime'],'sha256'=>$digest,'size'=>$stored['size'],
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        } catch (\Throwable $exception) {
            $storage->delete($stored['path']);
            // Concurrent duplicate file delivery: verify the winning manifest on retry.
            throw $exception;
        }
        return response()->json(['received'=>true],201);
    }
}
