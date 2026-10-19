<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\InquiryRfqInvitation;
use App\Models\InquiryTask;
use App\Services\LegacyInquiryService;
use App\Services\SecureDocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Supplier-file bytes arrive directly in the live Inquiry task; no polling or staging table. */
final class ReceiveSupplierInquiryTaskAction extends Controller
{
    use VerifySupplierQuotation;

    public function __invoke(Request $request, SecureDocumentStorage $storage, LegacyInquiryService $inquiries): JsonResponse
    {
        $multipart = str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data');
        $body = $multipart ? $request->input('data') : $request->getContent();
        $time = $request->header('X-Supplier-Timestamp');
        $event = $request->header('X-Supplier-Event');
        $signature = $request->header('X-Supplier-Signature');
        $digest = $request->header('X-Supplier-Digests');
        $secret = $this->bridgeSecret();
        abort_unless($secret && is_string($body) && strlen($body) <= 4096 && strlen($body) > 0
            && is_string($time) && ctype_digit($time) && abs(time() - (int) $time) <= 300
            && is_string($event) && Str::isUuid($event)
            && is_string($signature) && preg_match('/^[a-f0-9]{64}$/', $signature), 401);
        if ($multipart) {
            abort_unless(is_string($digest) && preg_match('/^[a-f0-9]{64}$/', $digest), 401);
        } else {
            abort_unless($digest === null || $digest === '', 401);
        }
        abort_unless(hash_equals(hash_hmac('sha256', $time."\n".$event."\n".$body.($multipart ? "\n".$digest : ''), $secret), $signature), 401);

        $data = Validator::make(json_decode($body, true) ?: [], [
            'event_id' => ['required', 'uuid', 'in:'.$event],
            'request_uuid' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'invitation_id' => ['required', 'integer', 'min:1'],
            'task_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:upload,complete'],
            'expected_status' => ['required', 'string', 'max:100'],
        ])->validate();
        abort_unless($multipart === ($data['action'] === 'upload'), 422);
        $invitation = $this->authorizeInvitation((int) $data['invitation_id'], (int) $data['supplier_id']);
        abort_if($invitation->inquiry()->whereNotNull('result')->exists(), 409, 'Inquiry is closed.');
        abort_if($invitation->due_at?->isPast(), 409, 'RFQ deadline has passed.');
        $supplierName = (string) ($invitation->supplier()->value('name') ?: 'Supplier');
        // The signed file digest is a stable idempotency key across browser retries.
        $note = 'Supplier RFQ '.$invitation->id.' | '.($multipart ? $digest : $data['request_uuid']);
        $uploaded = null;
        $stored = null;
        if ($multipart) {
            $uploaded = $request->validate([
                'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp,gif,zip'],
            ])['file'];
            abort_unless(hash_equals($digest, hash_file('sha256', $uploaded->getRealPath())), 422, 'File checksum mismatch.');
            $stored = $storage->store($uploaded, 'flowtrack/inquiries/'.$invitation->inquiry_id, 20971520);
        }

        try {
            return DB::transaction(function () use ($invitation, $data, $inquiries, $storage, $stored, $uploaded, $supplierName, $note): JsonResponse {
                $task = InquiryTask::query()->whereKey($data['task_id'])
                    ->where('inquiry_id', $invitation->inquiry_id)->lockForUpdate()->firstOrFail();
                $firstId = (int) InquiryTask::query()->where('inquiry_id', $invitation->inquiry_id)
                    ->orderBy('sequence')->orderBy('id')->value('id');
                abort_unless($firstId === (int) $task->id, 403, 'This task is not assigned to the supplier RFQ.');
                $task->load('inquiry');
                abort_if($task->inquiry->result, 409, 'Inquiry is closed.');
                if ($data['action'] === 'upload') {
                    // A retransmission of the same signed request must not duplicate its document.
                    $existing = $task->documents()->where('note', $note)->first();
                    if ($existing) {
                        if ($stored) $storage->delete($stored['path']);
                        return response()->json(['received' => true, 'status' => $task->status,
                            'completed' => (bool) $task->completed_at, 'file' => $this->fileReceipt($existing)]);
                    }
                }
                // An older portal could mark this task complete while retaining
                // only a filename locally. Permit one recovery upload ONLY if
                // FlowTracker has no file for this invitation. Do not reopen the
                // task or permit additional uploads to an already documented one.
                if ($task->completed_at) {
                    abort_unless($uploaded && $stored, 409, 'Task has already been completed.');
                    abort_if($task->documents()->where('note', 'like', 'Supplier RFQ '.$invitation->id.' |%')->exists(),
                        409, 'A supplier file already exists in FlowTracker. Refresh the RFQ.');
                }
                abort_unless(hash_equals((string) $task->status, $data['expected_status']), 409, 'Task status changed. Refresh the RFQ.');
                if ($uploaded && $stored) {
                    abort_if($task->documents()->count() >= InquiryTask::MAX_DOCUMENTS, 422, 'Maximum ten task files.');
                    $document = $task->documents()->create([
                        'inquiry_id' => $task->inquiry_id, 'uploaded_by' => null,
                        'name' => basename(str_replace('\\', '/', $uploaded->getClientOriginalName())),
                        'path' => $stored['path'], 'mime_type' => $stored['mime'],
                        'size' => $stored['size'], 'note' => $note,
                    ]);
                    $task->inquiry->activities()->create([
                        'user_id' => null, 'event' => 'inquiry.task_document_uploaded',
                        'description' => 'Supplier '.$supplierName.' uploaded '.$uploaded->getClientOriginalName().' to '.$task->title.'.',
                        'meta' => ['inquiry_task_id' => $task->id, 'supplier_invitation_id' => $invitation->id],
                    ]);
                    // If the HTTP acknowledgement is lost, resync this invitation
                    // only. Never broadcast confidential task files to peer RFQs.
                    DB::afterCommit(static fn () => app(\App\Services\SupplierBridge\DeliverRfq::class)->queue($invitation));
                    return response()->json(['received' => true, 'status' => $task->status,
                        'completed' => (bool) $task->completed_at, 'file' => $this->fileReceipt($document)]);
                }
                if ($task->requires_submission) {
                    abort_unless($task->documents()->where('note', 'like', 'Supplier RFQ '.$invitation->id.' |%')->exists(),
                        422, 'Upload your required costing file before completing this task.');
                }
                $completed = $inquiries->completeSupplierInquiryTask($task, $supplierName, (int) $invitation->id);
                return response()->json(['received' => true, 'status' => $completed->status, 'completed' => true]);
            }, 3);
        } catch (\Throwable $error) {
            if ($stored) $storage->delete($stored['path']);
            throw $error;
        }
    }

    private function fileReceipt(\App\Models\InquiryDocument $file): array
    {
        return [
            'id' => (int) $file->id,
            'name' => basename(str_replace('\\', '/', (string) $file->name)),
            'mime_type' => (string) $file->mime_type,
            'size' => (int) $file->size,
            // A fresh Eloquent timestamp can have fractional seconds which the
            // database column truncates. Let the authoritative RFQ resync send
            // the persisted version; an immediate upload receipt needs no version.
            'version' => '',
        ];
    }
}
