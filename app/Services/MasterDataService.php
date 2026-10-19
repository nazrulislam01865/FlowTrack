<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\MasterRecord;
use App\Models\MasterValue;
use App\Support\MasterColor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MasterDataService
{
    /** @var array<string,array<string,string>> */
    private array $colorMaps = [];

    /** @var array<string,MasterRecord>|null */
    private ?array $remoteAreaByPostalCode = null;

    /** @var array<int,array{from:string,to:string,record:MasterRecord}>|null */
    private ?array $remoteAreaPostalRanges = null;

    public const COLOR_TYPES = ['department', 'priority', 'task_status', 'inquiry_task_status', 'task_flag', 'order_task_status', 'order_task_flag', 'order_flag'];

    /** Financial setup values are governed by the Finance role-matrix module. */
    public const FINANCIAL_TYPES = [
        'currency',
        'received_account',
        'payment_method',
        'payment_term',
        'invoice_type',
    ];

    /** Task Pack efficiency/setup values managed from their own Master Data menu. */
    public const TASK_PACK_MASTER_TYPES = [
        'task_pack_duration_unit',
        'task_pack_timer_start',
        'task_pack_timer_stop',
        'task_pack_work_calendar',
    ];

    public const TASK_PACK_MASTER_DEFAULTS = [
        'task_pack_duration_unit' => [
            ['code' => 'TPD-001', 'name' => 'Business hours'],
            ['code' => 'TPD-002', 'name' => 'Calendar hours'],
            ['code' => 'TPD-003', 'name' => 'Business days'],
            ['code' => 'TPD-004', 'name' => 'Calendar days'],
        ],
        'task_pack_timer_start' => [
            ['code' => 'TPS-001', 'name' => 'When status changes to In Progress'],
        ],
        'task_pack_timer_stop' => [
            ['code' => 'TPE-001', 'name' => 'When status changes to Completed'],
        ],
        'task_pack_work_calendar' => [
            [
                'code' => 'TPW-001',
                'name' => 'Workspace hours',
                'metadata' => [
                    'day_from' => 'monday',
                    'day_to' => 'friday',
                    'time_from' => '09:00',
                    'time_to' => '18:00',
                ],
            ],
        ],
    ];

    public const ACCESS_MODULES = [
        'product' => 'catalog_products',
        'product_category' => 'product_categories',
        'supplier' => 'suppliers',
        'currency' => 'finance',
        'received_account' => 'finance',
        'payment_method' => 'finance',
        'payment_term' => 'finance',
        'invoice_type' => 'finance',
    ];

    public static function permissionModuleForType(string $type): string
    {
        return self::ACCESS_MODULES[$type] ?? 'masterdata';
    }

    public const LABELS = [
        'department' => 'Departments',
        'product_category' => 'Product Categories',
        'product' => 'Products',
        'supplier' => 'Suppliers',
        'production_unit' => 'Production Units',
        'shipment_method' => 'Shipment Methods',
        'courier' => 'Couriers',
        'remote_area' => 'Remote Areas',
        'currency' => 'Currencies',
        'invoice_type' => 'Invoice Types',
        'payment_term' => 'Payment Terms',
        'payment_method' => 'Payment Methods',
        'received_account' => 'Received Accounts',
        'country' => 'Countries',
        'state' => 'States',
        'phone_country_code' => 'Phone Country Codes',
        'document_category' => 'Document Categories',
        'priority' => 'Priorities',
        'production_urgency' => 'Production Urgencies',
        'shipment_urgency' => 'Shipment Urgencies',
        'task_status' => 'Legacy Task Statuses',
        'inquiry_task_status' => 'Inquiry Task Statuses',
        'task_flag' => 'Legacy Task Flags',
        'order_task_status' => 'Order Task Statuses',
        'order_task_flag' => 'Order Task Flags',
        'order_flag' => 'Order Flags',
        'task_pack_duration_unit' => 'Duration Units',
        'task_pack_timer_start' => 'Timer Start Rules',
        'task_pack_timer_stop' => 'Timer Stop Rules',
        'task_pack_work_calendar' => 'Work Calendars',
    ];

    /**
     * Stable prefixes used for automatically generated Master Data codes.
     *
     * Existing records keep their historical codes. New records created from
     * Master Data use the next available PREFIX-### value for their type.
     */
    public const CODE_PREFIXES = [
        'department' => 'DEP',
        'product_category' => 'CAT',
        'product' => 'PRD',
        'supplier' => 'SUP',
        'production_unit' => 'PUN',
        'shipment_method' => 'SHM',
        'courier' => 'COU',
        'remote_area' => 'RMA',
        'currency' => 'CUR',
        'invoice_type' => 'IVT',
        'payment_term' => 'PTR',
        'payment_method' => 'PMT',
        'received_account' => 'RCA',
        'country' => 'CTR',
        'state' => 'STA',
        'phone_country_code' => 'PNC',
        'document_category' => 'DOC',
        'priority' => 'PRI',
        'production_urgency' => 'PUR',
        'shipment_urgency' => 'SUR',
        'task_status' => 'TST',
        'inquiry_task_status' => 'IST',
        'task_flag' => 'TFL',
        'order_task_status' => 'OTS',
        'order_task_flag' => 'OTF',
        'order_flag' => 'ORF',
        'task_pack_duration_unit' => 'TPD',
        'task_pack_timer_start' => 'TPS',
        'task_pack_timer_stop' => 'TPE',
        'task_pack_work_calendar' => 'TPW',
    ];

    private const LEGACY_GROUPS = [
        'department' => 'departments',
        'product_category' => 'product_categories',
        'product' => 'products',
        'supplier' => 'suppliers',
        'production_unit' => 'production_units',
        'shipment_method' => 'shipment_methods',
        'remote_area' => 'remote_areas',
        'currency' => 'currencies',
        'country' => 'countries',
        'state' => 'states',
        'document_category' => 'document_categories',
        'priority' => 'priorities',
        'task_status' => 'task_statuses',
        'inquiry_task_status' => 'inquiry_task_statuses',
        'order_task_status' => 'order_task_statuses',
        'task_flag' => 'task_flags',
        'order_task_flag' => 'order_task_flags',
        'order_flag' => 'order_flags',
    ];

    public function workspaceId(): int { return app(SetupContext::class)->workspaceId(); }

    /**
     * Return the business currency value represented by a Currency master row.
     *
     * Master Data codes such as CUR-001 are internal record identifiers and must
     * never leak into orders/invoices as the currency itself. Prefer an explicit
     * ISO value from metadata, then a 3-letter name (for records named USD/EUR),
     * and finally a legacy 3-letter code.
     */
    public function currencyValue(MasterRecord $currency): string
    {
        $candidates = [
            data_get($currency->metadata, 'currency_code'),
            data_get($currency->metadata, 'iso_code'),
            data_get($currency->metadata, 'iso'),
            $currency->name,
            $currency->code,
        ];

        foreach ($candidates as $candidate) {
            $value = strtoupper(trim((string) $candidate));
            if (preg_match('/^[A-Z]{3}$/', $value)) return $value;
        }

        return '';
    }

    public function query(string $type, string $search = '', array $filters = [])
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $parentId = (int) ($filters['parent_id'] ?? 0);
        $mainCategory = trim((string) ($filters['main_category'] ?? ''));
        $clientAvailability = trim((string) ($filters['client_availability'] ?? ''));
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $supplierState = trim((string) ($filters['supplier_state'] ?? ''));

        return MasterRecord::query()
            ->forWorkspace($this->workspaceId())
            ->ofType($type)
            ->when(in_array($type, ['product', 'state'], true), fn ($q) => $q->with('parent'))
            ->when($type === 'product', fn ($q) => $q->with('creator'))
            ->when($search, function ($q) use ($search, $type) {
                $normalized = trim((string) $search);
                $productId = null;
                if ($type === 'product' && preg_match('/^PRD[-\s]*0*(\d+)$/i', $normalized, $matches)) {
                    $productId = (int) $matches[1];
                }

                $q->where(function ($x) use ($normalized, $productId, $type) {
                    $x->whereLike('code', "%{$normalized}%")
                        ->orWhereLike('name', "%{$normalized}%")
                        ->orWhereLike('description', "%{$normalized}%")
                        ->orWhereLike('metadata->reference_code', "%{$normalized}%");
                    if ($type === 'remote_area') {
                        $x->orWhereLike('metadata->postal_code', "%{$normalized}%")
                            ->orWhereLike('metadata->postal_code_from', "%{$normalized}%")
                            ->orWhereLike('metadata->postal_code_to', "%{$normalized}%")
                            ->orWhereLike('metadata->carrier', "%{$normalized}%")
                            ->orWhereLike('metadata->country', "%{$normalized}%")
                            ->orWhereLike('metadata->iata_code', "%{$normalized}%")
                            ->orWhereLike('metadata->city', "%{$normalized}%")
                            ->orWhereLike('metadata->origin_surcharge', "%{$normalized}%")
                            ->orWhereLike('metadata->destination_surcharge', "%{$normalized}%");
                    }
                    if ($productId) $x->orWhere('id', $productId);
                });
            })
            ->when(in_array($status, ['active', 'inactive'], true), fn ($q) => $q->where('status', $status))
            ->when($type === 'product' && $parentId > 0, fn ($q) => $q->where('parent_id', $parentId))
            ->when($type === 'product' && $supplierId > 0, function ($q) use ($supplierId) {
                $hasPivot = Schema::hasTable('product_supplier_links');
                $q->where(function ($supplier) use ($supplierId, $hasPivot) {
                    $supplier->where('metadata->supplier_id', $supplierId)
                        ->orWhere('metadata->default_supplier_id', $supplierId)
                        ->orWhereJsonContains('metadata->supplier_ids', $supplierId);

                    if ($hasPivot) {
                        $supplier->orWhereExists(function ($link) use ($supplierId): void {
                            $link->selectRaw('1')
                                ->from('product_supplier_links')
                                ->whereColumn('product_supplier_links.product_id', 'master_records.id')
                                ->where('product_supplier_links.supplier_id', $supplierId);
                        });
                    }
                });
            })
            ->when($type === 'product' && $supplierState === 'assigned', function ($q) {
                $hasPivot = Schema::hasTable('product_supplier_links');
                $q->where(function ($assigned) use ($hasPivot) {
                    $assigned->whereNotNull('metadata->supplier_id')
                        ->orWhereNotNull('metadata->default_supplier_id')
                        ->orWhereJsonLength('metadata->supplier_ids', '>', 0);

                    if ($hasPivot) {
                        $assigned->orWhereExists(function ($link): void {
                            $link->selectRaw('1')
                                ->from('product_supplier_links')
                                ->whereColumn('product_supplier_links.product_id', 'master_records.id');
                        });
                    }
                });
            })
            ->when($type === 'product' && $supplierState === 'unassigned', function ($q) {
                $hasPivot = Schema::hasTable('product_supplier_links');
                $q->whereNull('metadata->supplier_id')
                    ->whereNull('metadata->default_supplier_id')
                    ->where(function ($links) {
                        $links->whereNull('metadata->supplier_ids')
                            ->orWhereJsonLength('metadata->supplier_ids', 0);
                    });

                if ($hasPivot) {
                    $q->whereNotExists(function ($link): void {
                        $link->selectRaw('1')
                            ->from('product_supplier_links')
                            ->whereColumn('product_supplier_links.product_id', 'master_records.id');
                    });
                }
            })
            ->when($type === 'product' && $mainCategory !== '', function ($q) use ($mainCategory) {
                $q->where(function ($match) use ($mainCategory) {
                    $match->where('metadata->main_category', $mainCategory)
                        ->orWhere('metadata->excel_main_category', $mainCategory)
                        ->orWhereHas('parent', function ($parent) use ($mainCategory) {
                            $parent->where('metadata->excel_main_category', $mainCategory)
                                ->orWhere(function ($fallback) use ($mainCategory) {
                                    $fallback->whereNull('metadata')->where('name', $mainCategory);
                                });
                        });
                });
            })
            ->when($type === 'product' && in_array($clientAvailability, ['all', 'specific'], true), function ($q) use ($clientAvailability) {
                if ($clientAvailability === 'specific') {
                    $q->where(function ($specific) {
                        $specific->where('metadata->client_availability', 'specific')
                            ->orWhereNotNull('metadata->client_codes')
                            ->orWhereNotNull('metadata->client_availability_labels');
                    });
                    return;
                }

                $q->where(function ($all) {
                    $all->whereNull('metadata->client_codes')
                        ->whereNull('metadata->client_availability_labels')
                        ->where(function ($scope) {
                            $scope->whereNull('metadata->client_availability')
                                ->orWhere('metadata->client_availability', '')
                                ->orWhere('metadata->client_availability', 'all')
                                ->orWhere('metadata->client_availability', 'all clients');
                        });
                });
            })
            ->orderBy('sort_order')->orderBy('name');
    }

    public function list(string $type, string $search = '', array $filters = [])
    {
        return $this->query($type, $search, $filters)->get();
    }

    public function paginate(string $type, string $search = '', int $perPage = 30, array $filters = [])
    {
        $perPage = max(1, min(100, $perPage));

        return $this->query($type, $search, $filters)->paginate($perPage, ['*'], 'masterPage');
    }

    public function active(string $type)
    {
        abort_unless(array_key_exists($type, self::LABELS), 404);
        $workspaceId = $this->workspaceId();
        $rows = Cache::remember($this->activeCacheKey($workspaceId, $type), now()->addMinutes(5), fn () =>
            MasterRecord::query()->forWorkspace($workspaceId)->ofType($type)->active()->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (MasterRecord $record) => $record->getAttributes())->all()
        );

        return collect($rows)->map(fn (array $attributes) => (new MasterRecord())->newFromBuilder($attributes));
    }

    public function colorFor(string $type, ?string $value): ?string
    {
        if (!in_array($type, self::COLOR_TYPES, true)) return null;

        $value = strtolower(trim((string) $value));
        if ($value === '') return null;

        if (!array_key_exists($type, $this->colorMaps)) {
            $map = [];
            MasterRecord::query()
                ->forWorkspace($this->workspaceId())
                ->ofType($type)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['code', 'name', 'color'])
                ->each(function (MasterRecord $record) use (&$map, $type): void {
                    $color = MasterColor::normalize($record->color) ?: MasterColor::defaultFor($type, $record->name);

                    $name = strtolower(trim((string) $record->name));
                    $code = strtolower(trim((string) $record->code));
                    if ($name !== '') $map[$name] = $color;
                    if ($code !== '') $map[$code] = $color;
                });

            $this->colorMaps[$type] = $map;
        }

        return $this->colorMaps[$type][$value] ?? null;
    }

    public function displayColorFor(string $type, ?string $value): ?string
    {
        if (!in_array($type, self::COLOR_TYPES, true)) return null;
        if (trim((string) $value) === '') return null;

        return $this->colorFor($type, $value) ?: MasterColor::defaultFor($type, $value);
    }

    public function colorStyleFor(string $type, ?string $value): string
    {
        return MasterColor::style($this->displayColorFor($type, $value));
    }

    public function nextCode(string $type): string
    {
        abort_unless(array_key_exists($type, self::LABELS), 404);

        $prefix = self::CODE_PREFIXES[$type];
        $workspaceId = $this->workspaceId();
        $pattern = '/^'.preg_quote($prefix, '/').'-(\d+)$/';

        // Include soft-deleted rows because the database unique index still
        // reserves their code. This prevents a deleted code from being reused.
        $highest = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType($type)
            ->where('code', 'like', $prefix.'-%')
            ->pluck('code')
            ->reduce(function (int $max, string $code) use ($pattern): int {
                if (!preg_match($pattern, strtoupper($code), $matches)) return $max;
                return max($max, (int) $matches[1]);
            }, 0);

        $next = $highest + 1;
        do {
            $code = $prefix.'-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while (MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType($type)
            ->where('code', $code)
            ->exists());

        return $code;
    }

    public function save(string $type, array $data, ?int $id = null): MasterRecord
    {
        $this->assertAction($type, $id ? 'edit' : 'create');
        abort_unless(array_key_exists($type, self::LABELS), 404);
        $workspaceId = $this->workspaceId();
        $code = strtoupper(trim($data['code']));
        $duplicate = MasterRecord::query()->forWorkspace($workspaceId)->ofType($type)->where('code', $code)->when($id, fn ($q) => $q->whereKeyNot($id))->exists();
        if ($duplicate) throw ValidationException::withMessages(['code' => 'This code already exists in the selected master data type.']);

        $parentId = null;
        $parentType = match ($type) {
            'product' => 'product_category',
            'state' => 'country',
            default => null,
        };
        if ($parentType && filled($data['parent_id'] ?? null)) {
            $parentId = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType($parentType)
                ->whereKey((int) $data['parent_id'])
                ->value('id');

            if (!$parentId) {
                $label = $type === 'state' ? 'Country' : 'Product Category';
                throw ValidationException::withMessages(['parentId' => 'Select a valid '.$label.'.']);
            }
        }

        if ($type === 'state' && !$parentId) {
            throw ValidationException::withMessages(['parentId' => 'Select the country this state belongs to.']);
        }

        if ($type === 'remote_area') {
            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $legacyPostalCode = $this->normalizePostalCode((string) ($metadata['postal_code'] ?? ''));
            $postalFrom = $this->normalizePostalCode((string) ($metadata['postal_code_from'] ?? $legacyPostalCode));
            $postalTo = $this->normalizePostalCode((string) ($metadata['postal_code_to'] ?? $postalFrom));
            $city = trim((string) ($metadata['city'] ?? ''));

            if ($postalFrom === '' && $city === '') {
                throw ValidationException::withMessages(['remoteAreaPostalCodeFrom' => 'Enter a postal code range or a city.']);
            }
            if ($postalFrom === '' && $postalTo !== '') {
                throw ValidationException::withMessages(['remoteAreaPostalCodeTo' => 'Enter Postal Code From before Postal Code To.']);
            }
            if ($postalFrom !== '' && $postalTo === '') $postalTo = $postalFrom;
            if ($postalFrom !== '' && $postalTo !== '' && $this->postalRangeIsDescending($postalFrom, $postalTo)) {
                throw ValidationException::withMessages(['remoteAreaPostalCodeTo' => 'Postal Code To must be greater than or equal to Postal Code From.']);
            }

            $carrier = trim((string) ($metadata['carrier'] ?? 'UPS')) ?: 'UPS';
            $country = trim((string) ($metadata['country'] ?? ''));
            $iataCode = strtoupper(trim((string) ($metadata['iata_code'] ?? '')));
            if ($iataCode !== '' && ! preg_match('/^[A-Z]{2}$/', $iataCode)) {
                throw ValidationException::withMessages(['remoteAreaIataCode' => 'IATA code must contain exactly two letters.']);
            }

            $originSurcharge = trim((string) ($metadata['origin_surcharge'] ?? 'No')) ?: 'No';
            $destinationSurcharge = trim((string) ($metadata['destination_surcharge'] ?? 'No')) ?: 'No';
            $validOrigins = ['No', 'Extended Area Surcharge', 'Pickup Area Surcharge', 'Pickup Area Surcharge - Extended', 'Remote Area Surcharge', 'Remote Area Surcharge - Extended'];
            $validDestinations = ['No', 'Extended Area Surcharge', 'Delivery Area Surcharge', 'Delivery Area Surcharge - Extended', 'Remote Area Surcharge', 'Remote Area Surcharge - Extended'];
            if (! in_array($originSurcharge, $validOrigins, true)) {
                throw ValidationException::withMessages(['remoteAreaOriginSurcharge' => 'Select a valid origin surcharge.']);
            }
            if (! in_array($destinationSurcharge, $validDestinations, true)) {
                throw ValidationException::withMessages(['remoteAreaDestinationSurcharge' => 'Select a valid destination surcharge.']);
            }

            $metadata['carrier'] = $carrier;
            if ($country !== '') $metadata['country'] = $country; else unset($metadata['country']);
            if ($iataCode !== '') $metadata['iata_code'] = $iataCode; else unset($metadata['iata_code']);
            if ($postalFrom !== '') $metadata['postal_code_from'] = $postalFrom; else unset($metadata['postal_code_from']);
            if ($postalTo !== '') $metadata['postal_code_to'] = $postalTo; else unset($metadata['postal_code_to']);
            if ($city !== '') $metadata['city'] = $city; else unset($metadata['city']);
            $metadata['origin_surcharge'] = $originSurcharge;
            $metadata['destination_surcharge'] = $destinationSurcharge;

            // Preserve metadata.postal_code only for historical records that
            // already use it. The Order matcher now reads postal_code_from /
            // postal_code_to directly for new UPS-style exact and range rules.
            if ($legacyPostalCode !== '' && $postalFrom !== '' && $postalFrom === $postalTo) {
                $metadata['postal_code'] = $postalFrom;
            } else {
                unset($metadata['postal_code']);
            }

            $identity = $this->remoteAreaIdentityKey($metadata);
            $duplicateArea = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('remote_area')
                ->when($id, fn ($query) => $query->whereKeyNot($id))
                ->get(['id', 'metadata'])
                ->contains(fn (MasterRecord $record) => $this->remoteAreaIdentityKey((array) ($record->metadata ?? [])) === $identity);
            if ($identity !== '' && $duplicateArea) {
                throw ValidationException::withMessages(['remoteAreaPostalCodeFrom' => 'This carrier, country and area rule already exists in Remote Areas.']);
            }

            $extraCharge = $metadata['extra_charge'] ?? null;
            if ($extraCharge === null || trim((string) $extraCharge) === '') {
                unset($metadata['extra_charge']);
            } else {
                if (! is_numeric($extraCharge) || (float) $extraCharge < 0 || (float) $extraCharge > 999999.99) {
                    throw ValidationException::withMessages(['remoteAreaExtraCharge' => 'Extra charge must be a number between 0 and 999999.99.']);
                }
                $metadata['extra_charge'] = round((float) $extraCharge, 2);
            }
            $data['metadata'] = $metadata;
        }

        if ($type === 'order_task_status' && filled(data_get($data, 'metadata.order_task_flag_id'))) {
            $mappedFlag = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('order_task_flag')
                ->whereKey((int) data_get($data, 'metadata.order_task_flag_id'))
                ->whereNull('deleted_at')
                ->first();

            if ($mappedFlag?->systemKey() === 'overdue') {
                throw ValidationException::withMessages([
                    'orderTaskFlagId' => 'Overdue is automatic from the due date and cannot be assigned to an Order Task Status.',
                ]);
            }
        }

        $record = DB::transaction(function () use ($type, $data, $id, $workspaceId, $code, $parentId) {
            $record = $id
                ? MasterRecord::query()->forWorkspace($workspaceId)->ofType($type)->findOrFail($id)
                : new MasterRecord();

            // Record the original creator once. Edits must never replace the
            // user who originally created the Master Data record.
            if (! $record->exists) {
                $record->created_by = auth()->id();
            }

            $record->fill([
                'workspace_id' => $workspaceId,
                'parent_id' => $parentId,
                'type' => $type,
                'code' => $code,
                'name' => trim($data['name']),
                'description' => blank($data['description'] ?? null) ? null : trim($data['description']),
                'color' => in_array($type, self::COLOR_TYPES, true)
                    ? (MasterColor::normalize($data['color'] ?? null) ?: MasterColor::defaultFor($type, $data['name'] ?? null))
                    : null,
                'metadata' => $data['metadata'] ?? null,
                'status' => ($data['status'] ?? 'active') === 'active' ? 'active' : 'inactive',
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ])->save();

            // Tasks keep a foreign key to Task Flags. Mirror the current Master
            // Data name into attention_reason as a compatibility/search field so
            // every existing list immediately follows Task Flag renames.
            if ($record->type === 'task_flag' && Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'task_flag_id')) {
                DB::table('tasks')
                    ->where('task_flag_id', $record->id)
                    ->update(['attention_reason' => $record->name]);
            }

            $this->mirrorLegacy($record);
            $this->forgetActiveCache($record->type);
            return $record;
        });

        // Inquiry Task Status is the canonical catalogue for Inquiry tasks.
        // Keep existing task rows synchronized after a Master Data rename or
        // flag/mapping change, then recalculate the parent Inquiry status.
        if ($record->type === 'inquiry_task_status'
            && Schema::hasTable('inquiry_tasks')
            && Schema::hasColumn('inquiry_tasks', 'inquiry_task_status_id')) {
            $inquiryIds = DB::table('inquiry_tasks')
                ->where('inquiry_task_status_id', $record->id)
                ->whereNull('deleted_at')
                ->distinct()
                ->pluck('inquiry_id')
                ->filter()
                ->map(fn ($value) => (int) $value)
                ->values();

            $updates = [
                'status' => $record->name,
                'needs_attention' => $record->requiresAttention(),
            ];
            if (!$record->requiresAttention()) {
                $updates['attention_reason'] = null;
            }

            DB::table('inquiry_tasks')
                ->where('inquiry_task_status_id', $record->id)
                ->update($updates);

            foreach ($inquiryIds as $inquiryId) {
                if ($inquiry = Inquiry::query()->find($inquiryId)) {
                    app(InquiryService::class)->syncAutomaticStatus($inquiry);
                }
            }
        }



        // Order task statuses and flags are independent from Inquiry master data.
        // Any mapping/name/color change must immediately recalculate affected
        // Order tasks and their persisted parent Order flag.
        if (in_array($record->type, ['order_task_status', 'order_task_flag', 'order_flag'], true)
            && Schema::hasTable('tasks')) {
            $taskIds = collect();

            if ($record->type === 'order_task_status' && Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'order_task_status_id')) {
                $taskIds = DB::table('tasks')
                    ->where('order_task_status_id', $record->id)
                    ->whereNull('deleted_at')
                    ->pluck('id');
                DB::table('tasks')->where('order_task_status_id', $record->id)->update(['status' => $record->name]);
            } elseif ($record->type === 'order_task_flag' && Schema::hasColumn('tasks', 'order_task_flag_id')) {
                $taskIds = DB::table('tasks')
                    ->where('order_task_flag_id', $record->id)
                    ->whereNull('deleted_at')
                    ->pluck('id');
            } elseif ($record->type === 'order_flag' && Schema::hasColumn('flow_jobs', 'order_flag_id')) {
                DB::table('flow_jobs')
                    ->where('order_flag_id', $record->id)
                    ->whereNull('deleted_at')
                    ->pluck('id')
                    ->each(function ($jobId): void {
                        if ($job = \App\Models\FlowJob::query()->find($jobId)) {
                            app(OrderTaskFlagService::class)->syncJob($job);
                        }
                    });
            }

            $taskIds->each(function ($taskId): void {
                if ($task = \App\Models\Task::query()->find($taskId)) {
                    app(OrderTaskFlagService::class)->syncTask($task);
                }
            });
        }

        // Several catalogue changes intentionally propagate to existing Task /
        // InquiryTask compatibility columns through set-based SQL. Those updates
        // bypass Eloquent observers, so publish a final invalidation after every
        // propagation step has completed. Duplicate model-observer signals are
        // safely coalesced by WorkspaceRefreshService.
        app(WorkspaceRefreshService::class)->touch('MasterRecord:propagated');

        return $record;
    }

    public function setColor(int $id, string $color): MasterRecord
    {
        $record = MasterRecord::query()->forWorkspace($this->workspaceId())->findOrFail($id);
        $this->assertAction($record->type, 'edit');
        abort_unless(in_array($record->type, self::COLOR_TYPES, true), 404);

        $normalized = MasterColor::normalize($color);
        if (!$normalized) {
            throw ValidationException::withMessages(['color' => 'Choose a valid 6-digit hex color.']);
        }

        $record->update(['color' => $normalized]);
        $this->mirrorLegacy($record);
        $this->forgetActiveCache($record->type);

        return $record;
    }

    public function toggle(int $id): MasterRecord
    {
        $record = MasterRecord::query()->forWorkspace($this->workspaceId())->findOrFail($id);
        $this->assertAction($record->type, 'edit');
        if (in_array($record->type, ['order_task_flag', 'order_flag'], true)
            && data_get($record->metadata, 'system_key') === 'overdue'
            && $record->status === 'active') {
            throw ValidationException::withMessages(['record' => 'The automatic Overdue flag must remain active.']);
        }
        if ($record->status === 'active' && $record->type === 'order_task_flag'
            && DB::table('master_records')->where('type', 'order_task_status')->whereNull('deleted_at')->where('metadata->order_task_flag_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['record' => 'This Order Task Flag is mapped to an Order Task Status. Remove that mapping before deactivating it.']);
        }
        if ($record->status === 'active' && $record->type === 'order_flag'
            && DB::table('master_records')->where('type', 'order_task_flag')->whereNull('deleted_at')->where('metadata->order_flag_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['record' => 'This Order Flag is mapped to an Order Task Flag. Remove that mapping before deactivating it.']);
        }
        $record->update(['status' => $record->status === 'active' ? 'inactive' : 'active']);
        $this->mirrorLegacy($record);
        $this->forgetActiveCache($record->type);

        if (in_array($record->type, ['order_task_status', 'order_task_flag', 'order_flag'], true)
            && Schema::hasTable('tasks')
            && Schema::hasColumn('tasks', 'order_task_flag_id')) {
            app(OrderTaskFlagService::class)->syncOpenTasks();
        }

        return $record;
    }

    public function delete(int $id): void
    {
        $record = MasterRecord::query()->forWorkspace($this->workspaceId())->findOrFail($id);
        $this->assertAction($record->type, 'delete');

        // Inquiry Task Statuses remain removable because Inquiry tasks keep the
        // historical text label and the soft-deleted Master Data row remains
        // resolvable for historical mapping. Delete the legacy mirror too so
        // syncLegacy() cannot restore the status as active.
        if ($record->type === 'inquiry_task_status') {
            if (Schema::hasTable('master_values')) {
                MasterValue::where('group_key', self::LEGACY_GROUPS['inquiry_task_status'])
                    ->where('code', $record->code)
                    ->delete();
            }

            $record->delete();
            $this->forgetActiveCache('inquiry_task_status');
            return;
        }

        if ($record->children()->exists()) throw ValidationException::withMessages(['record' => 'Remove or reassign child records before deleting this record.']);
        if (Schema::hasTable('task_pack_items') && in_array($record->type, self::TASK_PACK_MASTER_TYPES, true)) {
            $column = match ($record->type) {
                'task_pack_duration_unit' => 'standard_duration_unit',
                'task_pack_timer_start' => 'timer_start_rule',
                'task_pack_timer_stop' => 'timer_stop_rule',
                'task_pack_work_calendar' => 'work_calendar',
                default => null,
            };

            $inUse = $column && Schema::hasColumn('task_pack_items', $column)
                ? DB::table('task_pack_items')->where($column, $record->code)->exists()
                : false;


            if ($inUse) {
                throw ValidationException::withMessages(['record' => 'This Task Pack Master Data value is already used by a Task Pack. Deactivate it instead.']);
            }
        }
        if (DB::table('task_pack_items')->where('default_department_id', $id)->orWhere('priority_id', $id)->orWhere('document_category_id', $id)->exists()) {
            throw ValidationException::withMessages(['record' => 'This record is used by a Task Pack and cannot be deleted. Deactivate it instead.']);
        }
        if (Schema::hasColumn('workflow_phases', 'document_category_id') && DB::table('workflow_phases')->where('document_category_id', $id)->exists()) {
            throw ValidationException::withMessages(['record' => 'This record is used by a Workflow phase and cannot be deleted. Deactivate it instead.']);
        }
        if ($record->type === 'task_flag' && Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'task_flag_id') && DB::table('tasks')->where('task_flag_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['record' => 'This Task Flag is already assigned to one or more tasks and cannot be deleted. Deactivate it instead.']);
        }
        if ($record->type === 'order_task_status' && Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'order_task_status_id') && DB::table('tasks')->where('order_task_status_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['record' => 'This Order Task Status is already used by tasks and cannot be deleted. Deactivate it instead.']);
        }
        if ($record->type === 'order_task_flag') {
            if (data_get($record->metadata, 'system_key') === 'overdue') {
                throw ValidationException::withMessages(['record' => 'The automatic Overdue Order Task Flag is a system flag and cannot be deleted.']);
            }
            if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'order_task_flag_id') && DB::table('tasks')->where('order_task_flag_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['record' => 'This Order Task Flag is already used by tasks and cannot be deleted. Deactivate it instead.']);
            }
            if (DB::table('master_records')->where('type', 'order_task_status')->whereNull('deleted_at')->where('metadata->order_task_flag_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['record' => 'This Order Task Flag is mapped to an Order Task Status. Remove that mapping first.']);
            }
        }
        if ($record->type === 'order_flag') {
            if (data_get($record->metadata, 'system_key') === 'overdue') {
                throw ValidationException::withMessages(['record' => 'The automatic Overdue Order Flag is a system flag and cannot be deleted.']);
            }
            if (Schema::hasTable('flow_jobs') && Schema::hasColumn('flow_jobs', 'order_flag_id') && DB::table('flow_jobs')->where('order_flag_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['record' => 'This Order Flag is already used by orders and cannot be deleted. Deactivate it instead.']);
            }
            if (DB::table('master_records')->where('type', 'order_task_flag')->whereNull('deleted_at')->where('metadata->order_flag_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['record' => 'This Order Flag is mapped to an Order Task Flag. Remove that mapping first.']);
            }
        }
        if ($record->type === 'product' && data_get($record->metadata, 'product_image_path')) {
            app(ProductImageService::class)->remove($record);
        }
        if ($record->type === 'product' && data_get($record->metadata, 'product_options')) {
            app(ProductOptionImageService::class)->removeAll($record);
        }

        $legacyGroup = self::LEGACY_GROUPS[$record->type] ?? Str::plural($record->type);
        if (Schema::hasTable('master_values')) MasterValue::where('group_key', $legacyGroup)->where('code', $record->code)->delete();
        $type = $record->type;
        $record->delete();
        $this->forgetActiveCache($type);
    }

    public function syncLegacy(): void
    {
        if (!Schema::hasTable('master_values') || !Schema::hasTable('master_records')) return;
        $workspaceId = $this->workspaceId();
        $syncKey = 'flowtrack:master:legacy-sync:'.$workspaceId;
        if (Cache::get($syncKey)) return;

        foreach (MasterValue::query()->get() as $legacy) {
            $type = array_search($legacy->group_key, self::LEGACY_GROUPS, true) ?: Str::singular($legacy->group_key);
            MasterRecord::query()->firstOrCreate(
                ['workspace_id' => $workspaceId, 'type' => $type, 'code' => $legacy->code],
                [
                    'name' => $legacy->name,
                    'description' => $legacy->description,
                    'color' => in_array($type, self::COLOR_TYPES, true)
                        ? (MasterColor::normalize(data_get($legacy->meta, 'color')) ?: MasterColor::defaultFor($type, $legacy->name))
                        : null,
                    'metadata' => $legacy->meta,
                    'status' => $legacy->is_active ? 'active' : 'inactive',
                    'sort_order' => (int) $legacy->id,
                ]
            );
        }

        // Products and States are hierarchical master-data types. Preserve
        // Product -> Product Category and State -> Country links while keeping
        // all other master-data categories flat.
        MasterRecord::query()->forWorkspace($workspaceId)->whereNotIn('type', ['product', 'state'])->whereNotNull('parent_id')->update(['parent_id' => null]);
        foreach (MasterValue::query()->where('group_key', self::LEGACY_GROUPS['product'])->whereNotNull('parent_id')->get() as $legacyProduct) {
            $legacyCategory = MasterValue::query()->whereKey($legacyProduct->parent_id)->first();
            if (!$legacyCategory || $legacyCategory->group_key !== self::LEGACY_GROUPS['product_category']) continue;

            $categoryId = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product_category')
                ->where('code', $legacyCategory->code)
                ->value('id');

            if ($categoryId) {
                MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('product')
                    ->where('code', $legacyProduct->code)
                    ->whereNull('parent_id')
                    ->update(['parent_id' => $categoryId]);
            }
        }

        foreach (MasterValue::query()->where('group_key', self::LEGACY_GROUPS['state'])->whereNotNull('parent_id')->get() as $legacyState) {
            $legacyCountry = MasterValue::query()->whereKey($legacyState->parent_id)->first();
            if (!$legacyCountry || $legacyCountry->group_key !== self::LEGACY_GROUPS['country']) continue;

            $countryId = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('country')
                ->where('code', $legacyCountry->code)
                ->value('id');

            if ($countryId) {
                MasterRecord::query()
                    ->forWorkspace($workspaceId)
                    ->ofType('state')
                    ->where('code', $legacyState->code)
                    ->whereNull('parent_id')
                    ->update(['parent_id' => $countryId]);
            }
        }

        // Older demo/legacy Product rows did not always have parent_id set.
        // Their description begins with the Product Category name, e.g.
        // "Backpacks & Bags · Custom". Link only when that prefix exactly
        // matches a real category in this workspace; otherwise leave it alone.
        foreach (MasterRecord::query()->forWorkspace($workspaceId)->ofType('product')->whereNull('parent_id')->get(['id', 'description', 'metadata']) as $product) {
            if (filter_var(data_get($product->metadata, 'taxonomy_unassigned', false), FILTER_VALIDATE_BOOL)) continue;
            $categoryName = trim(explode(' ·', trim((string) $product->description), 2)[0]);
            if ($categoryName === '') continue;

            $categoryId = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product_category')
                ->where('name', $categoryName)
                ->value('id');

            if ($categoryId) {
                $product->update(['parent_id' => $categoryId]);
            }
        }

        Cache::put($syncKey, true, now()->addMinutes(5));
    }

    private function mirrorLegacy(MasterRecord $record): void
    {
        if (!Schema::hasTable('master_values')) return;
        $group = self::LEGACY_GROUPS[$record->type] ?? Str::plural($record->type);
        $legacyParentId = null;
        $parentType = match ($record->type) {
            'product' => 'product_category',
            'state' => 'country',
            default => null,
        };
        if ($parentType && $record->parent_id) {
            $parent = MasterRecord::query()
                ->forWorkspace($record->workspace_id)
                ->ofType($parentType)
                ->find($record->parent_id);
            if ($parent) {
                $legacyParentId = MasterValue::query()
                    ->where('group_key', self::LEGACY_GROUPS[$parentType])
                    ->where('code', $parent->code)
                    ->value('id');
            }
        }

        $legacyMeta = (array) ($record->metadata ?? []);
        if (in_array($record->type, self::COLOR_TYPES, true) && MasterColor::normalize($record->color)) {
            $legacyMeta['color'] = MasterColor::normalize($record->color);
        }

        MasterValue::query()->updateOrCreate(
            ['group_key' => $group, 'code' => $record->code],
            [
                'name' => $record->name,
                'description' => $record->description,
                'parent_id' => $legacyParentId,
                'is_active' => $record->status === 'active',
                'meta' => $legacyMeta ?: null,
            ]
        );
    }
    public function normalizePostalCode(string $postalCode): string
    {
        $postalCode = strtoupper(trim($postalCode));
        return (string) preg_replace('/\s+/', ' ', $postalCode);
    }

    public function postalRangeIsDescending(string $from, string $to): bool
    {
        $fromKey = $this->postalCodeMatchKey($from);
        $toKey = $this->postalCodeMatchKey($to);
        if ($fromKey === '' || $toKey === '') return false;

        $fromShape = $this->postalCodeRangeShape($fromKey);
        $toShape = $this->postalCodeRangeShape($toKey);

        return $fromShape !== null
            && $fromShape === $toShape
            && strcmp($fromKey, $toKey) > 0;
    }

    public function remoteAreaForPostalCode(?string $postalCode): ?MasterRecord
    {
        $matchKey = $this->postalCodeMatchKey((string) $postalCode);
        if ($matchKey === '') return null;

        // Build request-local exact and range indexes from the cached active
        // Remote Area collection. New UPS-style rows store postal_code_from /
        // postal_code_to, while older rows can still carry metadata.postal_code.
        // Both formats must drive the Order flag and invoice surcharge.
        if ($this->remoteAreaByPostalCode === null || $this->remoteAreaPostalRanges === null) {
            $this->remoteAreaByPostalCode = [];
            $this->remoteAreaPostalRanges = [];

            foreach ($this->active('remote_area') as $record) {
                $legacyKey = $this->postalCodeMatchKey($record->remoteAreaPostalCode());
                $fromKey = $this->postalCodeMatchKey($record->remoteAreaPostalCodeFrom());
                $toKey = $this->postalCodeMatchKey($record->remoteAreaPostalCodeTo());

                // Preserve legacy exact matching and also make single-value
                // UPS rows (From = To) immediately matchable by Orders.
                $exactKeys = array_unique([
                    $legacyKey,
                    $fromKey !== '' && ($toKey === '' || $fromKey === $toKey) ? $fromKey : '',
                ]);
                foreach ($exactKeys as $key) {
                    if ($key !== '' && ! isset($this->remoteAreaByPostalCode[$key])) {
                        $this->remoteAreaByPostalCode[$key] = $record;
                    }
                }

                if ($fromKey !== '' && $toKey !== '' && $fromKey !== $toKey) {
                    $this->remoteAreaPostalRanges[] = [
                        'from' => $fromKey,
                        'to' => $toKey,
                        'record' => $record,
                    ];
                }
            }
        }

        // Exact rows win over a broader range when both happen to cover the
        // same code. This keeps explicitly configured exceptions deterministic.
        if (isset($this->remoteAreaByPostalCode[$matchKey])) {
            return $this->remoteAreaByPostalCode[$matchKey];
        }

        foreach ($this->remoteAreaPostalRanges as $range) {
            if ($this->postalCodeFallsWithinRange($matchKey, $range['from'], $range['to'])) {
                return $range['record'];
            }
        }

        return null;
    }

    private function postalCodeFallsWithinRange(string $candidate, string $from, string $to): bool
    {
        if ($candidate === '' || $from === '' || $to === '') return false;

        // Postal codes are identifiers, not integers. Compare normalized values
        // only when all three have the same letter/digit shape (for example
        // DDDDD, LLDDD or LLDLDLL). This preserves leading zeroes and supports
        // mixed alphanumeric UPS ranges without letting unrelated formats bleed
        // into one another.
        $candidateShape = $this->postalCodeRangeShape($candidate);
        $fromShape = $this->postalCodeRangeShape($from);
        $toShape = $this->postalCodeRangeShape($to);
        if ($candidateShape === null || $candidateShape !== $fromShape || $fromShape !== $toShape) return false;
        if (strcmp($from, $to) > 0) return false;

        return strcmp($candidate, $from) >= 0 && strcmp($candidate, $to) <= 0;
    }

    private function postalCodeRangeShape(string $postalCode): ?string
    {
        if ($postalCode === '') return null;

        $shape = '';
        foreach (str_split($postalCode) as $character) {
            if ($character >= '0' && $character <= '9') {
                $shape .= 'D';
            } elseif ($character >= 'A' && $character <= 'Z') {
                $shape .= 'L';
            } else {
                // Punctuation can still be used in an exact rule, but is not
                // interpreted as an ordered range boundary.
                return null;
            }
        }

        return $shape;
    }

    private function remoteAreaIdentityKey(array $metadata): string
    {
        $carrier = mb_strtolower(trim((string) ($metadata['carrier'] ?? 'UPS')));
        $country = mb_strtolower(trim((string) ($metadata['country'] ?? '')));
        $iata = strtoupper(trim((string) ($metadata['iata_code'] ?? '')));
        $from = $this->postalCodeMatchKey((string) ($metadata['postal_code_from'] ?? $metadata['postal_code'] ?? ''));
        $to = $this->postalCodeMatchKey((string) ($metadata['postal_code_to'] ?? $from));
        $city = mb_strtolower(trim((string) ($metadata['city'] ?? '')));
        if ($from === '' && $city === '') return '';
        return implode('|', [$carrier, $country, $iata, $from, $to, $city]);
    }

    private function postalCodeMatchKey(string $postalCode): string
    {
        $normalized = $this->normalizePostalCode($postalCode);
        return (string) preg_replace('/\s+/', '', $normalized);
    }

    private function activeCacheKey(int $workspaceId, string $type): string
    {
        return "flowtrack:master:active:{$workspaceId}:{$type}";
    }

    private function forgetActiveCache(string $type): void
    {
        Cache::forget($this->activeCacheKey($this->workspaceId(), $type));
        unset($this->colorMaps[$type]);
        if ($type === 'remote_area') {
            $this->remoteAreaByPostalCode = null;
            $this->remoteAreaPostalRanges = null;
        }
    }

    private function assertAction(string $type, string $action): void
    {
        $user = auth()->user();
        $module = self::permissionModuleForType($type);
        abort_unless($user && app(AccessControlService::class)->can($user, $module, $action), 403);
    }

}
