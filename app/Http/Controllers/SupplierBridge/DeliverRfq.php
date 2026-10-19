<?php

namespace App\Services\SupplierBridge;

use App\Models\InquiryRfqInvitation;
use App\Models\InquiryTask;
use App\Models\MasterRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Durable, supplier-scoped RFQ projection. Does not publish buyer/customer identities. */
final class DeliverRfq
{
    public function queue(InquiryRfqInvitation $invitation): void
    {
        if (! config('supplier_bridge.enabled') || ! Schema::hasTable('supplier_rfq_deliveries')) return;
        // Do not overwrite an in-flight HTTP claim. A dirty marker requests
        // a fresh snapshot after the current delivery finishes.
        DB::table('supplier_rfq_deliveries')->insertOrIgnore([
            'invitation_id' => $invitation->id, 'supplier_id' => $invitation->supplier_id,
            'status' => 'pending', 'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_rfq_deliveries')->where('invitation_id', $invitation->id)
            ->where('status', 'sending')->update(['next_attempt_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_rfq_deliveries')->where('invitation_id', $invitation->id)
            ->whereIn('status', ['pending', 'delivered'])->update([
                'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null,
                'locked_at' => null, 'delivered_at' => null, 'updated_at' => now(),
            ]);
        $id = DB::table('supplier_rfq_deliveries')->where('invitation_id', $invitation->id)->value('id');
        $dispatch = static function () use ($id): void {
            if (config('queue.default') === 'sync' || ! $id) return;
            try {
                \App\Jobs\DeliverSupplierRfq::dispatch((int) $id)->onQueue('supplier-critical');
            } catch (Throwable $exception) {
                Log::warning('Supplier RFQ retained for scheduled retry.', ['delivery_id' => $id]);
            }
        };
        if (DB::transactionLevel() > 0) DB::afterCommit($dispatch);
        else $dispatch();
    }

    public function send(int $id): bool
    {
        $claimed = DB::table('supplier_rfq_deliveries')->where('id', $id)
            ->where(function ($query): void {
                $query->where('status', 'pending')->orWhere(fn ($stale) => $stale->where('status', 'sending')->where('locked_at', '<', now()->subMinutes(5)));
            })
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->update(['status' => 'sending', 'locked_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) return false;

        $delivery = DB::table('supplier_rfq_deliveries')->find($id);
        try {
            $invitation = InquiryRfqInvitation::query()->with([
                'inquiry:id,workspace_id,inquiry_number,subject,requirement_notes,required_delivery_date,currency,result',
                'inquiry.items:id,inquiry_id,item_name,category,quantity,unit,notes,sort_order',
                'inquiry.documents' => fn ($q) => $q->whereNull('inquiry_task_id')->select('id', 'inquiry_id', 'inquiry_task_id', 'name', 'size', 'mime_type', 'updated_at'),
                'inquiry.tasks' => fn ($q) => $q->orderBy('sequence')->orderBy('id')->limit(1),
            ])->findOrFail($delivery->invitation_id);
            if (! $invitation->invited_at || $invitation->email_status === 'Draft' || $invitation->inquiry?->result === 'dead') {
                throw new RuntimeException('RFQ invitation is not active for delivery.');
            }
            $inquiry = $invitation->inquiry;
            // A single bounded product lookup resolves display codes only when the
            // Inquiry name/category uniquely identifies the catalogue product.
            $scopedIds = $invitation->scopedItemIds();
            $sourceItems = $scopedIds === [] ? $inquiry->items : $inquiry->items->whereIn('id', $scopedIds);
            $names = $sourceItems->pluck('item_name')->filter()->unique()->all();
            $catalog = MasterRecord::query()->forWorkspace((int) $inquiry->workspace_id)
                ->ofType('product')->whereIn('name', $names)
                ->with('parent:id,name')->limit(501)->get(['id', 'parent_id', 'name', 'type', 'metadata', 'code']);
            $codes = [];
            if ($catalog->count() <= 500) {
                foreach ($catalog as $product) {
                    $key = mb_strtolower(trim((string) $product->name)).'|'.mb_strtolower(trim((string) ($product->parent?->name ?? '')));
                    $codes[$key][] = $product->productDisplayCode();
                }
            }
            $items = $sourceItems->map(static function ($item) use ($codes): array {
                $key = mb_strtolower(trim((string) $item->item_name)).'|'.mb_strtolower(trim((string) ($item->category ?? '')));
                $matches = $codes[$key] ?? [];
                return [
                    'id' => (int) $item->id,
                    'product_code' => count($matches) === 1 ? $matches[0] : '',
                    'product_name' => mb_substr((string) $item->item_name, 0, 255),
                    'category' => mb_substr((string) ($item->category ?? ''), 0, 150),
                    'quantity' => (float) $item->quantity,
                    'unit' => mb_substr((string) ($item->unit ?? ''), 0, 50),
                    'notes' => mb_substr((string) ($item->notes ?? ''), 0, 2000),
                ];
            })->all();
            if ($items === [] || count($items) > 100) throw new RuntimeException('RFQ must contain 1-100 products.');
            $firstTask = $inquiry->tasks->first();
            // The first inquiry task can have submissions from several RFQ
            // invitations. Include only this invitation's actual FlowTracker
            // files, including after task completion and on subsequent resync.
            $submittedFiles = $firstTask
                ? $firstTask->documents()->where('note', 'like', 'Supplier RFQ '.$invitation->id.' |%')
                    ->limit(InquiryTask::MAX_DOCUMENTS)->get(['id', 'name', 'mime_type', 'size', 'updated_at'])
                    ->map(static fn ($file): array => [
                        'id' => (int) $file->id,
                        'name' => mb_substr(basename(str_replace('\\', '/', (string) $file->name)), 0, 255),
                        'mime_type' => (string) $file->mime_type,
                        'size' => (int) $file->size,
                        'version' => (string) $file->updated_at?->format('Uu'),
                    ])->values()->all()
                : [];
            // Existing buyer-approved inquiry-level documents only. Task uploads may
            // contain private supplier costs and must never be shared with peers.
            $docs = $inquiry->documents->map(static fn ($document): array => [
                'id' => (int) $document->id,
                'name' => mb_substr(basename((string) $document->name), 0, 255),
                'size' => (int) $document->size,
                'mime_type' => (string) ($document->mime_type ?? 'application/octet-stream'),
                'version' => (string) $document->updated_at?->format('Uu'),
            ])->take(100)->all();
            if ($inquiry->documents->count() > 100) throw new RuntimeException('RFQ exceeds the maximum document count.');
            $payload = [
                'supplier_id' => (int) $delivery->supplier_id,
                'rfq' => [
                    'invitation_id' => (int) $invitation->id,
                    'reference' => 'RFQ-'.str_pad((string) $invitation->id, 6, '0', STR_PAD_LEFT),
                    'inquiry_reference' => (string) $inquiry->inquiry_number,
                    'subject' => mb_substr((string) $inquiry->subject, 0, 255),
                    'buyer_requirements' => mb_substr((string) ($inquiry->requirement_notes ?? ''), 0, 8000),
                    'request_message' => mb_substr((string) ($invitation->request_message ?? ''), 0, 2000),
                    'supplier_details' => mb_substr((string) ($invitation->supplier_details ?? ''), 0, 2000),
                    'currency' => (string) ($inquiry->currency ?: 'USD'),
                    'delivery_date' => $inquiry->required_delivery_date?->format('Y-m-d'),
                    'due_at' => $invitation->due_at?->toIso8601String(),
                    'items' => $items,
                    'documents' => $docs,
                    'supplier_task' => $firstTask ? [
                        'id' => (int) $firstTask->id,
                        'title' => mb_substr((string) $firstTask->title, 0, 255),
                        'instructions' => mb_substr(strip_tags((string) $firstTask->description), 0, 4000),
                        'status' => (string) $firstTask->status,
                        'completed' => $firstTask->completed_at !== null,
                        'requires_submission' => (bool) $firstTask->requires_submission,
                        'due_date' => $firstTask->due_date?->format('Y-m-d'),
                        'uploaded_files' => $submittedFiles,
                    ] : null,
                ],
            ];
            $response = app(PortalClient::class)->post('rfqs', $payload);
            if ($response->status() === 409) {
                // Most suppliers are already provisioned; sync catalog only on a
                // missing portal identity, avoiding an extra request per RFQ.
                if (! app(SyncSupplier::class)->sync((int) $delivery->supplier_id)) {
                    throw new RuntimeException('Supplier catalog sync has no assigned products.');
                }
                $response = app(PortalClient::class)->post('rfqs', $payload);
            }
            if (! $response->successful()) throw new RuntimeException('Supplier RFQ API returned HTTP '.$response->status().'.');
            $applied = DB::table('supplier_rfq_deliveries')->where('id', $id)
                ->where('status', 'sending')->whereNull('next_attempt_at')->update([
                'status' => 'delivered', 'delivered_at' => now(), 'last_error' => null,
                'next_attempt_at' => null, 'locked_at' => null, 'updated_at' => now(),
            ]);
            if ($applied !== 1) {
                DB::table('supplier_rfq_deliveries')->where('id', $id)
                    ->where('status', 'sending')->update([
                        'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null,
                        'locked_at' => null, 'updated_at' => now(),
                    ]);
                if (config('queue.default') !== 'sync') {
                    \App\Jobs\DeliverSupplierRfq::dispatch((int) $id)->onQueue('supplier-critical');
                }
                return true;
            }
            try {
                app(\App\Services\ReverbChannelService::class)->triggerSupplierDashboard((int) $delivery->supplier_id);
            } catch (\Throwable) {
                Log::warning('Supplier dashboard realtime unavailable.', ['delivery_id' => $id]);
            }
            return true;
        } catch (Throwable $exception) {
            $attempts = (int) $delivery->attempts + 1;
            DB::table('supplier_rfq_deliveries')->where('id', $id)->update([
                'status' => 'pending', 'attempts' => $attempts, 'locked_at' => null,
                'next_attempt_at' => now()->addSeconds(min(3600, 5 * (2 ** min($attempts - 1, 9)))),
                'last_error' => mb_substr($exception instanceof RuntimeException ? $exception->getMessage() : 'Supplier RFQ transport failed.', 0, 160),
                'updated_at' => now(),
            ]);
            Log::warning('Supplier RFQ API delivery deferred.', ['delivery_id' => $id, 'attempt' => $attempts]);
            return false;
        }
    }
}
