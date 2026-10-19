<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderShipment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'flow_job_id',
        'sequence',
        'is_primary',
        'recipient',
        'phone_country_code',
        'phone',
        'address',
        'city',
        'state',
        'postal_code',
        'country',
        'shipping_source_address_id',
        'shipment_method_id',
        'shipment_urgency_id',
        'courier_id',
        'package_reference',
        'quantity',
        'tracking_number',
        'label_printed_at',
        'dispatched_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_primary' => 'boolean',
            'quantity' => 'integer',
            'label_printed_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    public function sourceAddress(): BelongsTo
    {
        return $this->belongsTo(ClientShippingAddress::class, 'shipping_source_address_id');
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'shipment_method_id');
    }

    public function shipmentUrgency(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'shipment_urgency_id');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'courier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function fullPhone(): string
    {
        return trim(trim((string) $this->phone_country_code).' '.trim((string) $this->phone));
    }
}
