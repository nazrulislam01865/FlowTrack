<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRedo extends Model
{
    protected $fillable = [
        'original_order_id',
        'redo_order_id',
        'sequence',
        'issue_reported_by',
        'issue_category',
        'reported_date',
        'affected_quantity',
        'issue_description',
        'scope',
        'redo_quantity',
        'supplier_id',
        'internal_instructions',
        'customer_resolution',
        'customer_adjustment_type',
        'customer_adjustment_value',
        'customer_discount_percent',
        'supplier_adjustment_type',
        'supplier_adjustment_value',
        'supplier_redo_charge_percent',
        'deduct_freight',
        'freight_bearer',
        'freight_amount',
        'courier_freight_amount',
        'affected_order_value',
        'order_value_before_adjustment',
        'order_value_after_adjustment',
        'customer_impact',
        'supplier_redo_charge',
        'total_supplier_recovery',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reported_date' => 'date',
            'affected_quantity' => 'integer',
            'redo_quantity' => 'integer',
            'sequence' => 'integer',
            'customer_adjustment_value' => 'decimal:2',
            'customer_discount_percent' => 'decimal:2',
            'supplier_adjustment_value' => 'decimal:2',
            'supplier_redo_charge_percent' => 'decimal:2',
            'deduct_freight' => 'boolean',
            'freight_amount' => 'decimal:2',
            'courier_freight_amount' => 'decimal:2',
            'affected_order_value' => 'decimal:2',
            'order_value_before_adjustment' => 'decimal:2',
            'order_value_after_adjustment' => 'decimal:2',
            'customer_impact' => 'decimal:2',
            'supplier_redo_charge' => 'decimal:2',
            'total_supplier_recovery' => 'decimal:2',
        ];
    }

    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'original_order_id');
    }

    public function redoOrder(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'redo_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
