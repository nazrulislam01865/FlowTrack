<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Client extends Model
{
    protected $fillable = [
        'name',
        'code',
        'country',
        'contact_name',
        'email',
        'phone',
        'account_manager_id',
        'preferred_language',
        'outstanding_balance',
        'notes',
        'is_active',
        'office_address',
        'legal_business_name',
        'website',
        'preferred_currency',
        'contact_job_title',
        'office_address_line1',
        'office_suite',
        'office_city',
        'office_state',
        'office_zip',
        'billing_same_as_office',
        'billing_address_line1',
        'billing_suite',
        'billing_city',
        'billing_state',
        'billing_zip',
        'billing_country',
        'ein_tax_id',
        'sales_tax_status',
        'payment_terms',
        'po_required',
        'is_draft',
        'logo_path',
        'created_by',
        'archived_at',
        'archived_by',
        'purged_at',
        'purged_by',
        'billing_recipient',
    ];

    protected function casts(): array
    {
        return [
            'outstanding_balance' => 'decimal:2',
            'is_active' => 'boolean',
            'is_draft' => 'boolean',
            'billing_same_as_office' => 'boolean',
            'po_required' => 'boolean',
            'archived_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function accountManager(): BelongsTo { return $this->belongsTo(User::class, 'account_manager_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function archivedBy(): BelongsTo { return $this->belongsTo(User::class, 'archived_by'); }
    public function jobs(): HasMany { return $this->hasMany(FlowJob::class); }
    public function inquiries(): HasMany { return $this->hasMany(Inquiry::class); }
    public function tasks(): HasManyThrough { return $this->hasManyThrough(Task::class, FlowJob::class, 'client_id', 'flow_job_id'); }
    public function shippingAddresses(): HasMany { return $this->hasMany(ClientShippingAddress::class)->orderBy('sort_order'); }
    public function contacts(): HasMany { return $this->hasMany(ClientContact::class)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'); }
    public function deliveryContacts(): HasMany { return $this->hasMany(ClientDeliveryContact::class)->orderByDesc('last_used_at')->orderByDesc('id'); }

    public function logoUrl(): ?string
    {
        return app(\App\Services\StoredAssetUrlService::class)
            ->clientLogoUrl((int) $this->id, $this->logo_path);
    }
}
