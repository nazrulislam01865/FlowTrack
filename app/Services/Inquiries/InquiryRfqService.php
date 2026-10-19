<?php

namespace App\Services\Inquiries;

use App\Models\Activity;
use App\Models\Inquiry;
use App\Models\InquiryItem;
use App\Models\InquiryRfqInvitation;
use App\Models\InquiryRfqSetting;
use App\Models\InquiryRfqQuote;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\Email\ModuleEmailControlService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

final class InquiryRfqService
{
    public function __construct(
        private readonly InquiryRfqEmailService $mailer,
        private readonly ModuleEmailControlService $emailControl,
    ) {}

    public function emailEnabled(): bool
    {
        return $this->emailControl->inquiryEnabled();
    }

    /** @return array{invited:int,responses:int,submitted:int,awarded:int} */
    public function summary(Inquiry $inquiry): array
    {
        if (! Schema::hasTable('inquiry_rfq_invitations')) {
            return ['invited' => 0, 'responses' => 0, 'submitted' => 0, 'awarded' => 0];
        }

        $row = InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->selectRaw('COUNT(*) as invited')
            ->selectRaw("SUM(CASE WHEN interest_status <> 'pending' OR quote_status = 'submitted' THEN 1 ELSE 0 END) as responses")
            ->selectRaw("SUM(CASE WHEN quote_status = 'submitted' THEN 1 ELSE 0 END) as submitted")
            ->selectRaw('SUM(CASE WHEN awarded_at IS NOT NULL THEN 1 ELSE 0 END) as awarded')
            ->first();

        return [
            'invited' => (int) ($row?->invited ?? 0),
            'responses' => (int) ($row?->responses ?? 0),
            'submitted' => (int) ($row?->submitted ?? 0),
            'awarded' => (int) ($row?->awarded ?? 0),
        ];
    }

    public function defaultDueAt(Inquiry $inquiry): Carbon
    {
        $today = now()->startOfDay();
        $followUp = $inquiry->initial_follow_up_date ? Carbon::parse($inquiry->initial_follow_up_date)->endOfDay() : null;
        if ($followUp && $followUp->greaterThan($today)) return $followUp;

        $candidate = now()->addDays(7)->endOfDay();
        $delivery = $inquiry->required_delivery_date ? Carbon::parse($inquiry->required_delivery_date)->endOfDay() : null;
        if ($delivery && $delivery->greaterThan($today) && $delivery->lessThan($candidate)) {
            $due = $delivery->copy()->subDay()->endOfDay();
            $minimum = $today->copy()->addDay()->endOfDay();
            return $due->greaterThan($minimum) ? $due : $minimum;
        }

        return $candidate;
    }

    public function settings(Inquiry $inquiry): InquiryRfqSetting
    {
        $defaults = [
            'workspace_id' => (int) $inquiry->workspace_id,
            'inquiry_id' => (int) $inquiry->id,
            'special_note' => null,
            'supplier_details' => null,
            'default_due_at' => $this->defaultDueAt($inquiry),
            'link_validity_hours' => 720,
            'auto_reply_enabled' => true,
            'reminder_enabled' => true,
            'reminder_hours_before_due' => 24,
            'allow_revision' => true,
            'award_email_enabled' => true,
            'not_selected_email_enabled' => true,
        ];

        if (! Schema::hasTable('inquiry_rfq_settings')) {
            return new InquiryRfqSetting($defaults);
        }

        $settings = InquiryRfqSetting::query()->where('inquiry_id', $inquiry->id)->first();
        if (! $settings) {
            return new InquiryRfqSetting($defaults);
        }

        if (! $settings->default_due_at) {
            $settings->default_due_at = $this->defaultDueAt($inquiry);
        }

        return $settings;
    }

    /** @param array<string,mixed> $data */
    public function saveSettings(Inquiry $inquiry, array $data, User $actor): InquiryRfqSetting
    {
        abort_unless(Schema::hasTable('inquiry_rfq_settings'), 503, 'Run the latest database migrations before saving RFQ settings.');

        $settings = InquiryRfqSetting::query()->updateOrCreate(
            ['inquiry_id' => $inquiry->id],
            [
                'workspace_id' => (int) $inquiry->workspace_id,
                'special_note' => trim((string) ($data['special_note'] ?? '')) ?: null,
                'supplier_details' => trim((string) ($data['supplier_details'] ?? '')) ?: null,
                'default_due_at' => Carbon::parse((string) $data['default_due_at']),
                'link_validity_hours' => max(1, min(2160, (int) ($data['link_validity_hours'] ?? 720))),
                'auto_reply_enabled' => (bool) ($data['auto_reply_enabled'] ?? false),
                'reminder_enabled' => (bool) ($data['reminder_enabled'] ?? false),
                'reminder_hours_before_due' => max(1, min(336, (int) ($data['reminder_hours_before_due'] ?? 24))),
                'allow_revision' => (bool) ($data['allow_revision'] ?? false),
                'award_email_enabled' => (bool) ($data['award_email_enabled'] ?? true),
                'not_selected_email_enabled' => (bool) ($data['not_selected_email_enabled'] ?? true),
                'updated_by' => $actor->id,
            ],
        );

        InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->whereNull('awarded_at')
            ->whereNull('rejected_at')
            ->update([
                'auto_reply_enabled' => $settings->auto_reply_enabled,
                'reminder_enabled' => $settings->reminder_enabled,
                'reminder_hours_before_due' => $settings->reminder_hours_before_due,
                'allow_revision' => $settings->allow_revision,
                'updated_at' => now(),
            ]);

        InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->where(function ($query): void {
                $query->whereNull('invited_at')->orWhere('email_status', '!=', 'Delivered');
            })
            ->whereNull('awarded_at')
            ->whereNull('rejected_at')
            ->update([
                'due_at' => $settings->default_due_at,
                'request_message' => $settings->special_note,
                'supplier_details' => $settings->supplier_details,
                'updated_at' => now(),
            ]);

        $this->activity($inquiry, $actor, 'rfq.settings_updated', 'Supplier RFQ invitation settings were updated.', [
            'link_validity_hours' => (int) $settings->link_validity_hours,
            'auto_reply_enabled' => (bool) $settings->auto_reply_enabled,
            'reminder_enabled' => (bool) $settings->reminder_enabled,
            'allow_revision' => (bool) $settings->allow_revision,
            'award_email_enabled' => (bool) $settings->award_email_enabled,
            'not_selected_email_enabled' => (bool) $settings->not_selected_email_enabled,
        ]);
        app(\App\Services\WorkspaceRefreshService::class)->touch('InquiryRFQ:settings');

        return $settings->fresh();
    }

    /**
     * Return the Supplier-directory rows for the Inquiry Details RFQ picker.
     *
     * The picker must mirror Master Data > Suppliers rather than silently hiding
     * inactive suppliers or suppliers that still need an email address. Already
     * added suppliers are excluded from the picker because they are rendered in
     * the RFQ table immediately below it. Active rows remain selectable while
     * email_ready reports whether an invitation can be delivered immediately.
     *
     * @return Collection<int,array{id:int,name:string,email:string,contact:string,category:string,products:int,badge:?string,badge_tone:?string,status:string,invitable:bool,email_ready:bool,unavailable_reason:?string}>
     */
    public function candidateSuppliers(Inquiry $inquiry, string $search = '', int $limit = 100): Collection
    {
        $invitedIds = Schema::hasTable('inquiry_rfq_invitations')
            ? InquiryRfqInvitation::query()
                ->where('inquiry_id', $inquiry->id)
                ->pluck('supplier_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $defaultSupplierIds = $this->defaultSupplierProductNames($inquiry)
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();

        $excludeSupplierIds = collect($invitedIds)
            ->merge($defaultSupplierIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->supplierChoicesForWorkspace((int) $inquiry->workspace_id, $search, $limit, $excludeSupplierIds);
    }

    /**
     * Default Product suppliers that should appear in the RFQ send list before
     * an email is actually sent. This is read dynamically from the Inquiry
     * products so adding/removing products keeps the send list in sync without
     * creating a fake invitation record.
     *
     * @return Collection<int,array{id:int,name:string,email:string,product_names:array<int,string>,product_count:int,invitable:bool,email_ready:bool,unavailable_reason:?string}>
     */
    public function defaultSuppliersAwaitingSend(Inquiry $inquiry): Collection
    {
        $supplierProducts = $this->defaultSupplierProductNames($inquiry);
        if ($supplierProducts->isEmpty()) return collect();

        $alreadyInvited = Schema::hasTable('inquiry_rfq_invitations')
            ? InquiryRfqInvitation::query()
                ->where('inquiry_id', $inquiry->id)
                ->whereIn('supplier_id', $supplierProducts->keys()->all())
                ->pluck('supplier_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $pendingSupplierIds = $supplierProducts
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => in_array($id, $alreadyInvited, true))
            ->values();

        if ($pendingSupplierIds->isEmpty()) return collect();

        return MasterRecord::query()
            ->forWorkspace((int) $inquiry->workspace_id)
            ->ofType('supplier')
            ->whereIn('id', $pendingSupplierIds->all())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id','name','metadata','status','sort_order'])
            ->map(function (MasterRecord $supplier) use ($supplierProducts): array {
                $email = trim((string) data_get($supplier->metadata, 'email'));
                $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                $isActive = (string) $supplier->status === 'active';
                $productNames = collect($supplierProducts->get((int) $supplier->id, collect()))
                    ->map(fn ($name) => trim((string) $name))
                    ->filter()
                    ->unique()
                    ->values();

                $unavailableReason = ! $isActive ? 'Inactive' : null;

                return [
                    'id' => (int) $supplier->id,
                    'name' => (string) $supplier->name,
                    'short_code' => $supplier->supplierShortCode(),
                    'email' => $email,
                    'product_names' => $productNames->all(),
                    'product_count' => $productNames->count(),
                    // Active suppliers may participate in the RFQ even when an
                    // email address has not been configured yet. Email readiness
                    // is a delivery concern, not a supplier-selection rule.
                    'invitable' => $isActive,
                    'email_ready' => $validEmail,
                    'unavailable_reason' => $unavailableReason,
                ];
            })
            ->values();
    }

    /** @return array<int,int> */
    private function assignedSupplierIdsForInquiry(Inquiry $inquiry): array
    {
        $inquiry->loadMissing('items:id,inquiry_id,item_name,category,sort_order');
        $names = $inquiry->items
            ->pluck('item_name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) return [];

        $products = MasterRecord::query()
            ->forWorkspace((int) $inquiry->workspace_id)
            ->ofType('product')
            ->active()
            ->whereIn('name', $names->all())
            ->get(['id','metadata']);

        $supplierIds = $products
            ->flatMap(fn (MasterRecord $product) => $product->productSupplierIds())
            ->map(fn ($id) => (int) $id)
            ->filter();

        if (Schema::hasTable('product_supplier_links') && $products->isNotEmpty()) {
            $supplierIds = $supplierIds->concat(
                DB::table('product_supplier_links')
                    ->where('workspace_id', (int) $inquiry->workspace_id)
                    ->whereIn('product_id', $products->pluck('id')->all())
                    ->pluck('supplier_id')
                    ->map(fn ($id) => (int) $id)
            );
        }

        return $supplierIds->unique()->values()->all();
    }

    /**
     * Resolve Inquiry Product Master defaults as Supplier id => product names.
     * Inquiry items intentionally store a snapshot of the product name/category,
     * so match both fields to avoid choosing a same-named product from another
     * category.
     *
     * @return Collection<int,Collection<int,string>>
     */
    private function defaultSupplierProductNames(Inquiry $inquiry): Collection
    {
        $inquiry->loadMissing('items:id,inquiry_id,item_name,category,sort_order');

        $itemPairs = $inquiry->items
            ->map(fn (InquiryItem $item): array => [
                'name' => mb_strtolower(trim((string) $item->item_name)),
                'category' => mb_strtolower(trim((string) $item->category)),
            ])
            ->filter(fn (array $item): bool => $item['name'] !== '')
            ->values();

        if ($itemPairs->isEmpty()) return collect();

        $productNames = $inquiry->items
            ->pluck('item_name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();

        $products = MasterRecord::query()
            ->forWorkspace((int) $inquiry->workspace_id)
            ->ofType('product')
            ->active()
            ->whereIn('name', $productNames->all())
            ->with('parent:id,name,type,status')
            ->get(['id','parent_id','name','metadata','status','type']);

        $resolved = collect();
        foreach ($products as $product) {
            $name = mb_strtolower(trim((string) $product->name));
            $category = mb_strtolower(trim((string) $product->parent?->name));
            $matchesInquiryItem = $itemPairs->contains(
                fn (array $item): bool => $item['name'] === $name
                    && ($item['category'] === '' || $item['category'] === $category)
            );
            if (! $matchesInquiryItem) continue;

            $supplierId = $product->productSupplierId();
            if (! $supplierId) continue;

            $names = $resolved->get($supplierId, collect());
            $names->push((string) $product->name);
            $resolved->put($supplierId, $names);
        }

        return $resolved;
    }

    /**
     * Workspace-level source for callers that need only suppliers that can be
     * emailed immediately. The Inquiry Details/Create Inquiry pickers use the
     * broader supplierChoicesForWorkspace() directory source instead.
     *
     * @param array<int,int> $excludeSupplierIds
     * @return Collection<int,array{id:int,name:string,email:string,contact:string,category:string,products:int,badge:?string,badge_tone:?string,status:string,invitable:bool,email_ready:bool,unavailable_reason:?string}>
     */
    public function candidateSuppliersForWorkspace(int $workspaceId, string $search = '', int $limit = 20, array $excludeSupplierIds = []): Collection
    {
        $limit = max(1, min(50, $limit));

        return $this->supplierChoicesForWorkspace(
            $workspaceId,
            $search,
            min(100, max($limit * 3, 50)),
            $excludeSupplierIds,
        )
            ->filter(fn (array $supplier): bool => (bool) ($supplier['invitable'] ?? false) && (bool) ($supplier['email_ready'] ?? false))
            ->take($limit)
            ->values();
    }

    /**
     * Supplier-directory source for Create Inquiry RFQ selection.
     *
     * This intentionally mirrors the Supplier list instead of filtering records
     * out just because their email is not configured. Active suppliers remain
     * selectable RFQ participants; email_ready only controls whether an email can
     * be delivered immediately. Inactive suppliers remain visible but disabled.
     *
     * @param array<int,int> $excludeSupplierIds
     * @return Collection<int,array{id:int,name:string,email:string,contact:string,category:string,products:int,badge:?string,badge_tone:?string,status:string,invitable:bool,email_ready:bool,unavailable_reason:?string}>
     */
    public function supplierChoicesForWorkspace(int $workspaceId, string $search = '', int $limit = 50, array $excludeSupplierIds = []): Collection
    {
        $search = mb_strtolower(trim($search));
        $limit = max(1, min(100, $limit));
        $excludeSupplierIds = collect($excludeSupplierIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();

        // Match the Supplier list ordering and include active/inactive plus records
        // without email. A bounded read keeps this safe on larger workspaces while
        // still allowing search to reach records beyond the first visible rows.
        $query = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('supplier')
            ->when($excludeSupplierIds !== [], fn ($query) => $query->whereNotIn('id', $excludeSupplierIds))
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($search !== '') {
            $linkedSupplierIds = $this->supplierIdsMatchingProductContext($workspaceId, $search);

            $query->where(function ($match) use ($search, $linkedSupplierIds): void {
                $match->whereLike('code', "%{$search}%")
                    ->orWhereLike('name', "%{$search}%")
                    ->orWhereLike('description', "%{$search}%")
                    ->orWhereLike('metadata->contact_person', "%{$search}%")
                    ->orWhereLike('metadata->email', "%{$search}%");

                if ($linkedSupplierIds !== []) {
                    $match->orWhereIn('id', $linkedSupplierIds);
                }
            });
        }

        // Fetch enough rows for the requested result set before enriching them.
        $suppliers = $query
            ->limit($search === '' ? $limit : min(250, max($limit * 3, 100)))
            ->get(['id','name','code','description','metadata','status','sort_order']);

        $supplierIds = $suppliers->pluck('id')->map(fn ($id) => (int) $id)->all();
        $linkInfo = $this->supplierLinkInfo($workspaceId, $supplierIds);
        $performance = $this->supplierPerformance($workspaceId, $supplierIds);

        return $suppliers
            ->map(function (MasterRecord $supplier) use ($linkInfo, $performance): array {
                $email = trim((string) data_get($supplier->metadata, 'email'));
                $contact = trim((string) data_get($supplier->metadata, 'contact_person'));
                $info = $linkInfo->get((int) $supplier->id, ['products' => collect(), 'categories' => collect()]);
                $categories = collect($info['categories'] ?? [])->filter()->unique()->take(2)->implode(' · ');
                $metric = $performance->get((int) $supplier->id, []);
                $responseRate = isset($metric['response_rate']) ? (int) $metric['response_rate'] : null;
                $leadDays = isset($metric['lead_days']) ? (int) $metric['lead_days'] : null;
                $metadataLeadDays = (int) (data_get($supplier->metadata, 'lead_time_days') ?: data_get($supplier->metadata, 'supplier_lead_time_days') ?: 0);
                $leadDays = $leadDays ?: ($metadataLeadDays > 0 ? $metadataLeadDays : null);
                $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                $isActive = (string) $supplier->status === 'active';
                $invitable = $isActive;

                $badge = null;
                $badgeTone = null;
                if ($invitable && $responseRate !== null) {
                    $badge = $responseRate.'% response';
                    $badgeTone = 'green';
                } elseif ($invitable && $leadDays) {
                    $badge = $leadDays.'-day lead';
                    $badgeTone = 'blue';
                }

                $unavailableReason = ! $isActive ? 'Inactive' : null;

                return [
                    'id' => (int) $supplier->id,
                    'name' => (string) $supplier->name,
                    'short_code' => $supplier->supplierShortCode(),
                    'email' => $email,
                    'contact' => $contact,
                    'category' => $categories !== '' ? $categories : 'General supplier',
                    'products' => collect($info['products'] ?? [])->unique()->count(),
                    'badge' => $badge,
                    'badge_tone' => $badgeTone,
                    'status' => (string) $supplier->status,
                    'invitable' => $invitable,
                    'email_ready' => $validEmail,
                    'unavailable_reason' => $unavailableReason,
                ];
            })
            // Category names come from linked products rather than Supplier columns,
            // so keep the final in-memory match for category-only searches.
            ->filter(function (array $supplier) use ($search): bool {
                if ($search === '') return true;
                $haystack = mb_strtolower(implode(' ', [
                    $supplier['name'], $supplier['email'], $supplier['contact'], $supplier['category'],
                ]));
                return str_contains($haystack, $search);
            })
            ->take($limit)
            ->values();
    }

    /**
     * Resolve active RFQ participants without relying on the visible search
     * result limit. Email is intentionally not required here.
     *
     * @param array<int,int> $supplierIds
     * @return Collection<int,MasterRecord>
     */
    public function selectableSuppliersByIds(int $workspaceId, array $supplierIds): Collection
    {
        $ids = collect($supplierIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) return collect();

        return MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('supplier')
            ->active()
            ->whereIn('id', $ids->all())
            ->get(['id','name','code','metadata'])
            ->values();
    }

    /**
     * Backward-compatible helper for callers that specifically require immediate
     * email delivery capability.
     *
     * @param array<int,int> $supplierIds
     * @return Collection<int,MasterRecord>
     */
    public function invitableSuppliersByIds(int $workspaceId, array $supplierIds): Collection
    {
        return $this->selectableSuppliersByIds($workspaceId, $supplierIds)
            ->filter(fn (MasterRecord $supplier) => filter_var(trim((string) data_get($supplier->metadata, 'email')), FILTER_VALIDATE_EMAIL) !== false)
            ->values();
    }

    /**
     * Lightweight invitation source for the RFQ management table. The table only
     * needs supplier identity plus invitation status timestamps; quote items are
     * intentionally not hydrated here. The comparison tab continues to use the
     * full invitations() graph below.
     *
     * @return Collection<int,InquiryRfqInvitation>
     */
    public function managementInvitations(Inquiry $inquiry): Collection
    {
        if (! Schema::hasTable('inquiry_rfq_invitations')) return collect();

        return InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->with('supplier:id,name,code,metadata,status')
            ->orderBy('invited_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Lightweight product-overview invitation graph.
     *
     * Inquiry Overview needs delivery state plus quote-to-item links to render
     * product-level RFQ progress, but it does not need the full comparison data.
     *
     * @return Collection<int,InquiryRfqInvitation>
     */
    public function overviewInvitations(Inquiry $inquiry): Collection
    {
        if (! Schema::hasTable('inquiry_rfq_invitations')) return collect();

        return InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->with([
                'supplier:id,name,code,metadata,status',
                'quote:id,invitation_id,updated_at',
                'quote.items:id,quote_id,inquiry_item_id',
            ])
            ->orderBy('id')
            ->get([
                'id', 'inquiry_id', 'supplier_id', 'email_status', 'quote_status',
                'invited_at', 'quote_submitted_at', 'awarded_at', 'rejected_at', 'updated_at',
            ]);
    }

    /** @return Collection<int,InquiryRfqInvitation> */
    public function invitations(Inquiry $inquiry): Collection
    {
        if (! Schema::hasTable('inquiry_rfq_invitations')) return collect();

        return InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->with([
                'supplier:id,name,code,metadata,status',
                'quote:id,invitation_id,currency,freight,tooling_cost,sample_cost,tax_status,lead_time_days,sample_lead_time_days,incoterm,shipping_port,estimated_delivery_date,validity_days,specification_compliance,notes,document_notes,supporting_information,portal_details,submitted_total,created_at,updated_at',
                'quote.items:id,quote_id,inquiry_item_id,product_name,quantity,unit_price,moq,sort_order',
                'quote.documents:id,quote_id,document_type,name,size',
            ])
            ->orderBy('invited_at')
            ->orderBy('id')
            ->get();
    }

    public function invite(Inquiry $inquiry, int $supplierId, User $actor, ?Carbon $dueAt = null, ?string $requestMessage = null, bool $sendEmail = true, ?array $scopedItemIds = null): InquiryRfqInvitation
    {
        $workspaceId = (int) $inquiry->workspace_id;
        $supplier = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('supplier')
            ->active()
            ->findOrFail($supplierId, ['id','name','metadata','status']);

        $recipient = trim((string) data_get($supplier->metadata, 'email'));
        $emailReady = filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
        $emailEnabled = $this->emailControl->inquiryEnabled();

        if ($scopedItemIds !== null) {
            $available = $inquiry->items()->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $scopedItemIds = array_values(array_unique(array_map('intval', $scopedItemIds)));
            abort_if($scopedItemIds === [] || array_diff($scopedItemIds, $available), 422, 'RFQ supplier products are invalid.');
        }

        $existing = InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->where('supplier_id', $supplier->id)
            ->first();
        abort_if($existing, 422, 'This supplier has already been invited.');

        $token = Str::random(64);
        $settings = $this->settings($inquiry);
        $linkValidityHours = max(1, (int) ($settings->link_validity_hours ?: 720));
        $invitation = InquiryRfqInvitation::create([
            'workspace_id' => $workspaceId,
            'inquiry_id' => $inquiry->id,
            'supplier_id' => $supplier->id,
            'invited_by' => $actor->id,
            'token_hash' => hash('sha256', $token),
            'token_cipher' => Crypt::encryptString($token),
            'invited_at' => $sendEmail ? now() : null,
            'due_at' => $dueAt ?: ($settings->default_due_at ?: $this->defaultDueAt($inquiry)),
            'link_expires_at' => ($sendEmail && $emailReady && $emailEnabled) ? now()->addHours($linkValidityHours) : null,
            'request_message' => filled($requestMessage) ? trim((string) $requestMessage) : ($settings->special_note ?: null),
            'supplier_details' => trim((string) ($settings->supplier_details ?? '')) ?: null,
            'auto_reply_enabled' => (bool) $settings->auto_reply_enabled,
            'reminder_enabled' => (bool) $settings->reminder_enabled,
            'reminder_hours_before_due' => max(1, (int) ($settings->reminder_hours_before_due ?: 24)),
            'allow_revision' => (bool) $settings->allow_revision,
            'email_status' => ! $sendEmail ? 'Draft' : ($emailReady ? 'Sending' : 'No email'),
        ]);
        if ($scopedItemIds !== null) {
            \Illuminate\Support\Facades\DB::table('inquiry_rfq_invitation_items')->insert(
                array_map(fn (int $itemId): array => [
                    'invitation_id' => $invitation->id, 'inquiry_item_id' => $itemId,
                ], $scopedItemIds),
            );
        }
        $invitation->setRelation('supplier', $supplier);
        $inquiry->loadMissing('items:id,inquiry_id,item_name,category,quantity,unit,unit_price,notes,sort_order');
        $invitation->setRelation('inquiry', $inquiry);

        if ($sendEmail) {
            // Start the secure portal delivery immediately, without waiting for the mail provider.
            app(\App\Services\SupplierBridge\DeliverRfq::class)->queue($invitation);
        }

        if (! $sendEmail) {
            $this->activity($inquiry, $actor, 'rfq.draft', $supplier->name.' was added to the RFQ as a draft invitation.', [
                'supplier_id' => (int) $supplier->id,
                'invitation_id' => (int) $invitation->id,
            ]);
            app(\App\Services\WorkspaceRefreshService::class)->touch('InquiryRFQ:draft');
            return $invitation->fresh(['supplier','quote']);
        }

        if (! $emailEnabled && $emailReady) {
            $invitation->update(['email_status' => 'Email disabled']);
        }

        if (! $emailReady || ! $emailEnabled) {
            // An explicit send-on-create is an active supplier portal RFQ even
            // when email has not been configured or is centrally disabled.
            $reason = ! $emailReady
                ? 'because the supplier has no configured email address'
                : 'because the Inquiry email service is disabled by an administrator';
            $this->activity($inquiry, $actor, 'rfq.added', $supplier->name.' was added to the RFQ without sending email '.$reason.'.', [
                'supplier_id' => (int) $supplier->id,
                'invitation_id' => (int) $invitation->id,
                'email_service_disabled' => ! $emailEnabled,
            ]);
            app(\App\Services\WorkspaceRefreshService::class)->touch('InquiryRFQ:added');

            return $invitation->fresh(['supplier','quote']);
        }

        try {
            $trackingId = $this->mailer->sendInvitation($invitation, $token);
        } catch (Throwable $exception) {
            // Email failure must not prevent a legitimate supplier-portal RFQ.
            // Preserve the invitation for retry instead of deleting its scope.
            $invitation->update(['email_status' => 'Failed']);
            \Illuminate\Support\Facades\Log::warning('Supplier RFQ email failed; portal delivery still queued.', [
                'invitation_id' => $invitation->id,
            ]);
            return $invitation->fresh(['supplier', 'quote']);
        }

        // Keep the secure invitation valid even if a later status/audit write has
        // an unexpected problem after the provider has already accepted the mail.
        $invitation->update(['email_status' => 'Delivered', 'email_tracking_id' => $trackingId]);

        $this->activity($inquiry, $actor, 'rfq.invited', 'RFQ invitation sent to '.$supplier->name.'.', [
            'supplier_id' => (int) $supplier->id,
            'invitation_id' => (int) $invitation->id,
        ]);
        app(\App\Services\WorkspaceRefreshService::class)->touch('InquiryRFQ:invited');

        return $invitation->fresh(['supplier','quote']);
    }

    /**
     * Send a previously-added RFQ participant after an email address becomes
     * available (or retry a failed delivery). This keeps selection independent
     * from email availability while preserving the existing secure-link flow.
     */
    public function sendExistingInvitation(Inquiry $inquiry, int $invitationId, User $actor): InquiryRfqInvitation
    {
        $invitation = $this->existingInvitation($inquiry, $invitationId);
        abort_if($invitation->email_status === 'Delivered', 422, 'This RFQ invitation has already been sent.');

        return $this->deliverExistingInvitation($inquiry, $invitation, $actor, false);
    }

    /**
     * Send or resend the supplier's RFQ invitation from the management table.
     * Default suppliers do not have an invitation row yet, so they are created
     * lazily. Existing delivered invitations reuse the same secure token when
     * the user explicitly chooses Resend.
     */
    public function sendSupplierInvitation(Inquiry $inquiry, int $supplierId, User $actor): InquiryRfqInvitation
    {
        $existing = InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->where('supplier_id', $supplierId)
            ->with(['supplier','inquiry.items','quote'])
            ->first();

        if ($existing) {
            abort_if(
                $existing->awarded_at || $existing->rejected_at,
                422,
                'This supplier RFQ is already closed and does not need another invitation.'
            );
        }

        if (! $existing) {
            $assignedSupplierIds = $this->assignedSupplierIdsForInquiry($inquiry);
            abort_unless(
                in_array($supplierId, $assignedSupplierIds, true),
                422,
                'Assign this supplier to an Inquiry product before sending an invitation.'
            );

            // Create the participant first, then deliver it through the same
            // retry-safe path as existing rows. If delivery fails the Failed
            // state remains visible in the management table instead of the
            // supplier silently falling back to a fresh default row.
            $existing = $this->invite($inquiry, $supplierId, $actor, null, null, false);
            $existing->setRelation('inquiry', $inquiry);
        }

        return $this->deliverExistingInvitation($inquiry, $existing, $actor, true);
    }

    private function existingInvitation(Inquiry $inquiry, int $invitationId): InquiryRfqInvitation
    {
        return InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->whereKey($invitationId)
            ->with(['supplier','inquiry.items','quote'])
            ->firstOrFail();
    }

    private function deliverExistingInvitation(
        Inquiry $inquiry,
        InquiryRfqInvitation $invitation,
        User $actor,
        bool $allowResend,
    ): InquiryRfqInvitation {
        abort_if(
            ! $allowResend && $invitation->email_status === 'Delivered',
            422,
            'This RFQ invitation has already been sent.'
        );
        abort_unless($this->emailControl->inquiryEnabled(), 422, 'Inquiry email service is currently disabled by an administrator.');

        $recipient = $invitation->supplierEmail();
        abort_unless(filter_var($recipient, FILTER_VALIDATE_EMAIL), 422, 'Add a valid email address to this supplier before sending the RFQ invitation.');

        $wasDelivered = $invitation->email_status === 'Delivered';
        $token = Crypt::decryptString((string) $invitation->token_cipher);
        $settings = $this->settings($inquiry);
        $linkValidityHours = max(1, (int) ($settings->link_validity_hours ?: 720));
        $invitation->update([
            'due_at' => $settings->default_due_at ?: $invitation->due_at ?: $this->defaultDueAt($inquiry),
            'link_expires_at' => now()->addHours($linkValidityHours),
            'request_message' => trim((string) ($settings->special_note ?? '')) ?: null,
            'supplier_details' => trim((string) ($settings->supplier_details ?? '')) ?: null,
            'auto_reply_enabled' => (bool) $settings->auto_reply_enabled,
            'reminder_enabled' => (bool) $settings->reminder_enabled,
            'reminder_hours_before_due' => max(1, (int) ($settings->reminder_hours_before_due ?: 24)),
            'allow_revision' => (bool) $settings->allow_revision,
            'reminder_sent_at' => null,
            'email_status' => 'Sending',
        ]);

        try {
            $trackingId = $this->mailer->sendInvitation($invitation->fresh(['supplier','inquiry.items','quote']), $token);
        } catch (Throwable $exception) {
            $invitation->update(['email_status' => 'Failed']);
            throw $exception;
        }

        $invitation->update([
            'invited_at' => now(),
            'email_status' => 'Delivered',
            'email_tracking_id' => $trackingId,
        ]);

        app(\App\Services\SupplierBridge\DeliverRfq::class)->queue($invitation);

        $verb = $wasDelivered ? 'resent' : 'sent';
        $this->activity($inquiry, $actor, $wasDelivered ? 'rfq.resent' : 'rfq.invited', 'RFQ invitation '.$verb.' to '.$invitation->supplier?->name.'.', [
            'supplier_id' => (int) $invitation->supplier_id,
            'invitation_id' => (int) $invitation->id,
        ]);
        app(\App\Services\WorkspaceRefreshService::class)->touch($wasDelivered ? 'InquiryRFQ:resent' : 'InquiryRFQ:invited');

        return $invitation->fresh(['supplier','quote']);
    }

    public function findPublicInvitation(string $token): InquiryRfqInvitation
    {
        abort_if(trim($token) === '', 404);
        $invitation = InquiryRfqInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->with([
                'supplier:id,name,code,metadata,status',
                'inviter:id,name,email',
                'inquiry:id,workspace_id,inquiry_number,client_id,subject,requirement_notes,currency,required_delivery_date,result',
                'inquiry.client:id,name',
                'inquiry.items:id,inquiry_id,item_name,category,quantity,unit,unit_price,notes,sort_order',
                'quote:id,invitation_id,supplier_contact_name,supplier_contact_email,supplier_contact_phone,currency,freight,tooling_cost,sample_cost,discount,tax_status,lead_time_days,sample_lead_time_days,incoterm,shipping_port,estimated_delivery_date,validity_days,specification_compliance,notes,supporting_information,document_notes,submitted_by_name,submitted_by_email,submitted_total,created_at,updated_at',
                'quote.items:id,quote_id,inquiry_item_id,product_name,quantity,unit_price,moq,sort_order',
                'quote.documents:id,quote_id,document_type,name,path,mime_type,size,sort_order,created_at,updated_at',
            ])
            ->firstOrFail();

        abort_if($invitation->inquiry?->result === 'dead', 410, 'This request is no longer active.');
        abort_if($this->linkExpired($invitation), 410, 'This quotation link has expired. Contact the buyer if you need a new invitation.');
        return $invitation;
    }

    public function markDeclined(InquiryRfqInvitation $invitation): void
    {
        abort_if($invitation->awarded_at || $invitation->rejected_at, 422, 'This RFQ is already closed.');
        abort_if($invitation->quote_status === 'submitted', 422, 'A submitted quotation cannot be declined.');
        $invitation->update([
            'interest_status' => 'declined',
            'interest_at' => now(),
        ]);

        $this->activity($invitation->inquiry, null, 'rfq.declined', $invitation->supplier?->name.' declined the RFQ.', [
            'supplier_id' => (int) $invitation->supplier_id,
            'invitation_id' => (int) $invitation->id,
        ]);
        app(\App\Services\WorkspaceRefreshService::class)->touch('InquiryRFQ:declined');
    }

    public function beginQuoteRevision(InquiryRfqInvitation $invitation): InquiryRfqQuote
    {
        $invitation->loadMissing(['quote', 'supplier', 'inquiry']);
        abort_if($invitation->awarded_at || $invitation->rejected_at, 422, 'This RFQ is already closed.');
        abort_if($invitation->interest_status === 'declined', 422, 'This quotation request has been declined.');
        abort_unless($invitation->quote_status === 'submitted' && $invitation->quote, 422, 'Only a submitted quotation can be revised.');
        abort_unless((bool) ($invitation->allow_revision ?? true), 422, 'The buyer has disabled quotation revisions for this request.');
        abort_if($this->linkExpired($invitation), 422, 'This quotation link has expired.');

        $previousSubmittedAt = $invitation->quote_submitted_at;
        $quote = DB::transaction(function () use ($invitation, $previousSubmittedAt): InquiryRfqQuote {
            $invitation->update([
                'quote_status' => 'draft',
                'interest_status' => 'interested',
                'interest_at' => $invitation->interest_at ?: now(),
            ]);

            $this->activity($invitation->inquiry, null, 'rfq.quote_revision_started', $invitation->supplier?->name.' reopened its quotation for revision.', [
                'supplier_id' => (int) $invitation->supplier_id,
                'invitation_id' => (int) $invitation->id,
                'quote_id' => (int) $invitation->quote->id,
                'previous_submitted_at' => $previousSubmittedAt?->toIso8601String(),
            ]);

            return $invitation->quote->fresh(['items', 'documents']);
        });

        app(\App\Services\WorkspaceRefreshService::class)->touch('InquiryRFQ:quote-revision-started');

        return $quote;
    }

    /** @param array<int,array{inquiry_item_id:int,unit_price:float|int|string,moq?:float|int|string|null}> $items */
    public function submitQuote(InquiryRfqInvitation $invitation, array $items, array $data, ?callable $storePortalFiles = null): InquiryRfqQuote
    {
        abort_if($invitation->awarded_at || $invitation->rejected_at, 422, 'This RFQ is already closed.');
        abort_if($invitation->quote_status === 'submitted', 422, 'This quotation has already been submitted and can no longer be edited.');
        abort_if($this->linkExpired($invitation), 422, 'This quotation link has expired.');

        $inquiry = $invitation->inquiry()->with('items:id,inquiry_id,item_name,quantity,sort_order')->firstOrFail();
        $scopedIds = $invitation->scopedItemIds();
        $sourceItems = ($scopedIds === [] ? $inquiry->items : $inquiry->items->whereIn('id', $scopedIds))->keyBy('id');
        abort_if($sourceItems->isEmpty(), 422, 'This inquiry does not contain any products to quote.');

        $normalized = collect($items)->map(function (array $row) use ($sourceItems): array {
            $itemId = (int) ($row['inquiry_item_id'] ?? 0);
            /** @var InquiryItem|null $source */
            $source = $sourceItems->get($itemId);
            abort_unless($source, 422, 'One of the quoted products is no longer part of this inquiry.');
            $unitPrice = round((float) ($row['unit_price'] ?? 0), 4);
            abort_if($unitPrice < 0, 422, 'Unit price cannot be negative.');
            return [
                'inquiry_item_id' => $itemId,
                'product_name' => (string) $source->item_name,
                'quantity' => (float) $source->quantity,
                'unit_price' => $unitPrice,
                'moq' => filled($row['moq'] ?? null) ? max(0, (float) $row['moq']) : null,
                'sort_order' => (int) $source->sort_order,
            ];
        })->keyBy('inquiry_item_id');

        foreach ($sourceItems as $source) {
            abort_unless($normalized->has((int) $source->id), 422, 'Enter a unit price for every product.');
        }

        $freight = max(0, round((float) ($data['freight'] ?? 0), 2));
        $toolingCost = max(0, round((float) ($data['tooling_cost'] ?? 0), 2));
        $sampleCost = max(0, round((float) ($data['sample_cost'] ?? 0), 2));
        $discount = max(0, round((float) ($data['discount'] ?? 0), 2));
        $subtotal = $normalized->sum(fn (array $row) => ((float) $row['quantity']) * ((float) $row['unit_price']));
        $submittedTotal = round($subtotal + $freight + $toolingCost + $sampleCost - $discount, 2);

        $quote = DB::transaction(function () use ($invitation, $normalized, $data, $freight, $toolingCost, $sampleCost, $discount, $submittedTotal, $storePortalFiles): InquiryRfqQuote {
            $quote = InquiryRfqQuote::query()->updateOrCreate(
                ['invitation_id' => $invitation->id],
                [
                    'supplier_contact_name' => trim((string) ($data['supplier_contact_name'] ?? '')) ?: null,
                    'supplier_contact_email' => trim((string) ($data['supplier_contact_email'] ?? '')) ?: null,
                    'supplier_contact_phone' => trim((string) ($data['supplier_contact_phone'] ?? '')) ?: null,
                    'currency' => strtoupper(trim((string) ($data['currency'] ?? 'USD'))) ?: 'USD',
                    'freight' => $freight,
                    'tooling_cost' => $toolingCost,
                    'sample_cost' => $sampleCost,
                    'discount' => $discount,
                    'tax_status' => trim((string) ($data['tax_status'] ?? 'excluded')) ?: 'excluded',
                    'lead_time_days' => filled($data['lead_time_days'] ?? null) ? max(0, (int) $data['lead_time_days']) : null,
                    'sample_lead_time_days' => filled($data['sample_lead_time_days'] ?? null) ? max(0, (int) $data['sample_lead_time_days']) : null,
                    'incoterm' => trim((string) ($data['incoterm'] ?? '')) ?: null,
                    'shipping_port' => trim((string) ($data['shipping_port'] ?? '')) ?: null,
                    'estimated_delivery_date' => filled($data['estimated_delivery_date'] ?? null) ? $data['estimated_delivery_date'] : null,
                    'validity_days' => filled($data['validity_days'] ?? null) ? max(0, (int) $data['validity_days']) : null,
                    'specification_compliance' => trim((string) ($data['specification_compliance'] ?? '')) ?: null,
                    'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                    'supporting_information' => $data['supporting_information'] ?? null,
                    'document_notes' => trim((string) ($data['document_notes'] ?? '')) ?: null,
                    'submitted_by_name' => trim((string) ($data['submitted_by_name'] ?? $data['supplier_contact_name'] ?? '')) ?: null,
                    'submitted_by_email' => trim((string) ($data['submitted_by_email'] ?? $data['supplier_contact_email'] ?? '')) ?: null,
                    'submitted_total' => $submittedTotal,
                ],
            );

            $quote->items()->delete();
            $now = now();
            $quote->items()->insert($normalized->values()->map(fn (array $row) => $row + [
                'quote_id' => $quote->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            if ($storePortalFiles !== null) $storePortalFiles($quote);

            $invitation->update([
                'interest_status' => 'interested',
                'interest_at' => $invitation->interest_at ?: now(),
                'quote_status' => 'submitted',
                'quote_submitted_at' => now(),
            ]);

            return $quote;
        });

        $invitation->refresh()->loadMissing(['supplier','inquiry']);
        $this->activity($invitation->inquiry, null, 'rfq.quote_submitted', $invitation->supplier?->name.' submitted a quotation.', [
            'supplier_id' => (int) $invitation->supplier_id,
            'invitation_id' => (int) $invitation->id,
            'quote_id' => (int) $quote->id,
        ]);
        app(\App\Services\WorkspaceRefreshService::class)->touch('InquiryRFQ:quote-submitted');

        if ($this->emailControl->inquiryEnabled() && (bool) ($invitation->auto_reply_enabled ?? true)) {
            try {
                $this->mailer->sendQuoteReceived($invitation, $quote->fresh('items'));
            } catch (Throwable $exception) {
                Log::warning('flowtrack.rfq.quote_confirmation_failed', [
                    'invitation_id' => $invitation->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $quote->fresh('items');
    }

    public function submitSavedDraft(InquiryRfqInvitation $invitation): InquiryRfqQuote
    {
        $invitation->loadMissing(['quote.items', 'quote.documents', 'supplier', 'inquiry.items']);
        $quote = $invitation->quote;
        abort_unless($quote, 422, 'Complete the quotation before submitting.');

        $requiredDocumentTypes = ['formal_quotation', 'price_breakdown'];
        $documentTypes = collect($quote->documents ?? [])->pluck('document_type');
        abort_unless(collect($requiredDocumentTypes)->every(fn (string $type): bool => $documentTypes->contains($type)), 422, 'Upload the required quotation documents before submitting.');
        abort_unless(filled($quote->supplier_contact_name) && filled($quote->supplier_contact_email), 422, 'Complete the supplier contact details before submitting.');

        $items = collect($quote->items)->map(fn ($item): array => [
            'inquiry_item_id' => (int) $item->inquiry_item_id,
            'unit_price' => $item->unit_price,
            'moq' => $item->moq,
        ])->all();

        $data = [
            'supplier_contact_name' => $quote->supplier_contact_name,
            'supplier_contact_email' => $quote->supplier_contact_email,
            'supplier_contact_phone' => $quote->supplier_contact_phone,
            'currency' => $quote->currency,
            'freight' => $quote->freight,
            'tooling_cost' => $quote->tooling_cost,
            'sample_cost' => $quote->sample_cost,
            'discount' => $quote->discount,
            'tax_status' => $quote->tax_status,
            'lead_time_days' => $quote->lead_time_days,
            'sample_lead_time_days' => $quote->sample_lead_time_days,
            'incoterm' => $quote->incoterm,
            'shipping_port' => $quote->shipping_port,
            'estimated_delivery_date' => $quote->estimated_delivery_date?->format('Y-m-d'),
            'validity_days' => $quote->validity_days,
            'specification_compliance' => $quote->specification_compliance,
            'notes' => $quote->notes,
            'supporting_information' => $quote->supporting_information,
            'document_notes' => $quote->document_notes,
            'submitted_by_name' => $quote->supplier_contact_name,
            'submitted_by_email' => $quote->supplier_contact_email,
        ];

        return $this->submitQuote($invitation, $items, $data);
    }

    /** @return array{winner:InquiryRfqInvitation,email_failures:int,email_service_disabled:bool,award_email_enabled:bool,not_selected_email_enabled:bool} */
    public function award(Inquiry $inquiry, int $invitationId, User $actor): array
    {
        $winner = InquiryRfqInvitation::query()
            ->where('inquiry_id', $inquiry->id)
            ->whereKey($invitationId)
            ->with(['supplier','quote.items'])
            ->firstOrFail();
        abort_unless($winner->quote_status === 'submitted' && $winner->quote, 422, 'Only a submitted quotation can be awarded.');
        abort_if($winner->awarded_at, 422, 'This supplier has already been awarded.');

        DB::transaction(function () use ($inquiry, $winner): void {
            $existingWinner = InquiryRfqInvitation::query()
                ->where('inquiry_id', $inquiry->id)
                ->whereNotNull('awarded_at')
                ->lockForUpdate()
                ->first();
            abort_if($existingWinner, 422, 'A supplier has already been awarded for this inquiry.');

            InquiryRfqInvitation::query()
                ->where('inquiry_id', $inquiry->id)
                ->where('id', '!=', $winner->id)
                ->whereNull('rejected_at')
                ->update(['rejected_at' => now(), 'updated_at' => now()]);

            $winner->update(['awarded_at' => now(), 'rejected_at' => null]);
            $this->linkAwardedSupplierToProducts($inquiry, (int) $winner->supplier_id);
        });

        $this->activity($inquiry, $actor, 'rfq.awarded', $winner->supplier?->name.' was awarded the inquiry quotation.', [
            'supplier_id' => (int) $winner->supplier_id,
            'invitation_id' => (int) $winner->id,
            'quote_id' => (int) $winner->quote->id,
        ]);
        app(\App\Services\WorkspaceRefreshService::class)->touch('InquiryRFQ:awarded');

        $settings = $this->settings($inquiry);
        $awardEmailEnabled = (bool) ($settings->award_email_enabled ?? true);
        $notSelectedEmailEnabled = (bool) ($settings->not_selected_email_enabled ?? true);

        if (! $this->emailControl->inquiryEnabled()) {
            return [
                'winner' => $winner->fresh(['supplier','quote.items']),
                'email_failures' => 0,
                'email_service_disabled' => true,
                'award_email_enabled' => $awardEmailEnabled,
                'not_selected_email_enabled' => $notSelectedEmailEnabled,
            ];
        }

        $failures = 0;
        if ($awardEmailEnabled && filter_var($winner->supplierEmail(), FILTER_VALIDATE_EMAIL)) {
            try {
                $this->mailer->sendAward($winner->fresh(['supplier','inquiry.items','quote.items']), $actor);
            } catch (Throwable $exception) {
                $failures++;
                Log::warning('flowtrack.rfq.award_email_failed', ['invitation_id' => $winner->id, 'error' => $exception->getMessage()]);
            }
        }

        if ($notSelectedEmailEnabled) {
            $losers = InquiryRfqInvitation::query()
                ->where('inquiry_id', $inquiry->id)
                ->where('id', '!=', $winner->id)
                ->with(['supplier','inquiry.items'])
                ->get();
            foreach ($losers as $loser) {
                if (! filter_var($loser->supplierEmail(), FILTER_VALIDATE_EMAIL)) continue;
                try {
                    $this->mailer->sendNotSelected($loser);
                    $loser->update(['rejection_notified_at' => now()]);
                } catch (Throwable $exception) {
                    $failures++;
                    Log::warning('flowtrack.rfq.not_selected_email_failed', ['invitation_id' => $loser->id, 'error' => $exception->getMessage()]);
                }
            }
        }

        return [
            'winner' => $winner->fresh(['supplier','quote.items']),
            'email_failures' => $failures,
            'email_service_disabled' => false,
            'award_email_enabled' => $awardEmailEnabled,
            'not_selected_email_enabled' => $notSelectedEmailEnabled,
        ];
    }

    /** @return array{sent:int,failed:int} */
    public function sendDueReminders(): array
    {
        if (! Schema::hasTable('inquiry_rfq_invitations')) return ['sent' => 0, 'failed' => 0];
        if (! $this->emailControl->inquiryEnabled()) return ['sent' => 0, 'failed' => 0];

        $sent = 0;
        $failed = 0;
        $now = now();
        $latestRelevantDue = $now->copy()->addDays(14);

        InquiryRfqInvitation::query()
            ->where('email_status', 'Delivered')
            ->where('reminder_enabled', true)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('due_at')
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $latestRelevantDue)
            ->where('quote_status', '!=', 'submitted')
            ->where('interest_status', '!=', 'declined')
            ->whereNull('awarded_at')
            ->whereNull('rejected_at')
            ->with(['supplier','inquiry.items'])
            ->chunkById(100, function ($rows) use (&$sent, &$failed, $now): void {
                foreach ($rows as $invitation) {
                    $hoursBefore = max(1, (int) ($invitation->reminder_hours_before_due ?: 24));
                    $reminderAt = $invitation->due_at->copy()->subHours($hoursBefore);
                    if ($now->lessThan($reminderAt) || $this->linkExpired($invitation)) {
                        continue;
                    }

                    try {
                        $token = Crypt::decryptString((string) $invitation->token_cipher);
                        $this->mailer->sendReminder($invitation, $token);
                        $invitation->update(['reminder_sent_at' => now()]);
                        $sent++;
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::warning('flowtrack.rfq.reminder_failed', ['invitation_id' => $invitation->id, 'error' => $exception->getMessage()]);
                    }
                }
            });

        return ['sent' => $sent, 'failed' => $failed];
    }

    /** @return array<string,string> */
    public function previewHtml(Inquiry $inquiry): array
    {
        $inquiry->loadMissing('items');

        return $this->mailer->previewHtml($inquiry, $this->invitations($inquiry));
    }

    private function linkAwardedSupplierToProducts(Inquiry $inquiry, int $supplierId): void
    {
        $workspaceId = (int) $inquiry->workspace_id;
        $productNames = $inquiry->items()->pluck('item_name')->filter()->unique()->values();
        if ($productNames->isEmpty()) return;

        $products = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->whereIn('name', $productNames->all())
            ->get(['id','metadata']);

        $pivotRows = [];
        foreach ($products as $product) {
            $metadata = (array) ($product->metadata ?? []);
            $ids = collect($product->productSupplierIds())->push($supplierId)->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $metadata['supplier_ids'] = $ids->all();
            if (! $product->productSupplierId()) $metadata['supplier_id'] = $supplierId;
            $product->metadata = $metadata;
            $product->save();

            if (Schema::hasTable('product_supplier_links')) {
                $pivotRows[] = [
                    'workspace_id' => $workspaceId,
                    'product_id' => (int) $product->id,
                    'supplier_id' => $supplierId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        if ($pivotRows !== []) DB::table('product_supplier_links')->insertOrIgnore($pivotRows);
    }

    /** @return Collection<int,array{response_rate:?int,lead_days:?int}> */
    private function supplierPerformance(int $workspaceId, array $supplierIds): Collection
    {
        if ($supplierIds === [] || ! Schema::hasTable('inquiry_rfq_invitations')) return collect();

        $responses = InquiryRfqInvitation::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('supplier_id', $supplierIds)
            ->selectRaw('supplier_id, COUNT(*) as invitation_count')
            ->selectRaw("SUM(CASE WHEN interest_status <> 'pending' OR quote_status = 'submitted' THEN 1 ELSE 0 END) as response_count")
            ->groupBy('supplier_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->supplier_id);

        $leadTimes = collect();
        if (Schema::hasTable('inquiry_rfq_quotes')) {
            $leadTimes = DB::table('inquiry_rfq_quotes as quotes')
                ->join('inquiry_rfq_invitations as invitations', 'invitations.id', '=', 'quotes.invitation_id')
                ->where('invitations.workspace_id', $workspaceId)
                ->whereIn('invitations.supplier_id', $supplierIds)
                ->whereNotNull('quotes.lead_time_days')
                ->selectRaw('invitations.supplier_id, ROUND(AVG(quotes.lead_time_days)) as lead_days')
                ->groupBy('invitations.supplier_id')
                ->get()
                ->keyBy(fn ($row) => (int) $row->supplier_id);
        }

        return collect($supplierIds)->mapWithKeys(function (int $supplierId) use ($responses, $leadTimes): array {
            $response = $responses->get($supplierId);
            $count = (int) ($response?->invitation_count ?? 0);
            $responded = (int) ($response?->response_count ?? 0);
            $rate = $count > 0 ? (int) round(($responded / $count) * 100) : null;
            $leadDays = (int) ($leadTimes->get($supplierId)?->lead_days ?? 0);

            return [$supplierId => [
                'response_rate' => $rate,
                'lead_days' => $leadDays > 0 ? $leadDays : null,
            ]];
        });
    }

    /** @return Collection<int,array{products:Collection,categories:Collection}> */
    private function supplierLinkInfo(int $workspaceId, array $supplierIds): Collection
    {
        if ($supplierIds === []) return collect();

        if (Schema::hasTable('product_supplier_links')) {
            $rows = DB::table('product_supplier_links as links')
                ->join('master_records as products', 'products.id', '=', 'links.product_id')
                ->leftJoin('master_records as categories', 'categories.id', '=', 'products.parent_id')
                ->where('links.workspace_id', $workspaceId)
                ->whereIn('links.supplier_id', $supplierIds)
                ->whereNull('products.deleted_at')
                ->select(['links.supplier_id','products.id as product_id','products.name as product_name','categories.name as category_name'])
                ->get();

            return $rows->groupBy('supplier_id')->map(fn (Collection $group) => [
                'products' => $group->pluck('product_name')->filter()->unique()->values(),
                'categories' => $group->pluck('category_name')->filter()->unique()->values(),
            ]);
        }

        $result = collect();
        MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->with('parent:id,name')
            ->get(['id','parent_id','name','metadata'])
            ->each(function (MasterRecord $product) use (&$result, $supplierIds): void {
                foreach ($product->productSupplierIds() as $supplierId) {
                    $supplierId = (int) $supplierId;
                    if (! in_array($supplierId, $supplierIds, true)) continue;
                    $current = $result->get($supplierId, ['products' => collect(), 'categories' => collect()]);
                    $current['products']->push($product->name);
                    if ($product->parent?->name) $current['categories']->push($product->parent->name);
                    $result->put($supplierId, $current);
                }
            });

        return $result;
    }


    /** @return array<int,int> */
    private function supplierIdsMatchingProductContext(int $workspaceId, string $search): array
    {
        $search = trim($search);
        if ($search === '') return [];

        $products = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->where(function ($query) use ($search): void {
                $query->whereLike('code', "%{$search}%")
                    ->orWhereLike('name', "%{$search}%")
                    ->orWhereHas('parent', fn ($parent) => $parent->whereLike('name', "%{$search}%"));
            })
            ->limit(250)
            ->get(['id', 'metadata']);

        if ($products->isEmpty()) return [];

        $supplierIds = $products
            ->flatMap(fn (MasterRecord $product) => $product->productSupplierIds())
            ->map(fn ($id) => (int) $id)
            ->filter();

        if (Schema::hasTable('product_supplier_links')) {
            $supplierIds = $supplierIds->concat(
                DB::table('product_supplier_links')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('product_id', $products->pluck('id')->all())
                    ->pluck('supplier_id')
                    ->map(fn ($id) => (int) $id)
            );
        }

        return $supplierIds->unique()->values()->all();
    }

    private function linkExpired(InquiryRfqInvitation $invitation): bool
    {
        if ($invitation->link_expires_at) {
            return now()->greaterThan($invitation->link_expires_at);
        }

        return (bool) ($invitation->due_at && now()->greaterThan($invitation->due_at->copy()->addDays(30)));
    }

    private function activity(Inquiry $inquiry, ?User $actor, string $event, string $description, array $meta = []): void
    {
        Activity::create([
            'subject_type' => Inquiry::class,
            'subject_id' => $inquiry->id,
            'user_id' => $actor?->id,
            'event' => $event,
            'description' => $description,
            'meta' => $meta,
        ]);
    }
}
