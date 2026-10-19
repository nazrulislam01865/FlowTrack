<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowJobItem extends Model
{
    protected $table = 'flow_job_items';
    protected $fillable = [
        'flow_job_id',
        'product_name',
        'category_name',
        'quantity',
        'sort_order',
        'notes',
        'catalog_product_id',
        'supplier_id',
        'is_removed',
        'removed_at',
        'removed_by',
        'removal_reason',
        'unit_price',
        'supplier_unit_price',
        'remote_area_surcharge',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'supplier_unit_price' => 'decimal:2',
            'remote_area_surcharge' => 'decimal:2',
            'is_removed' => 'boolean',
            'removed_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    /** Catalog product selected when the line was created. */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'catalog_product_id');
    }

    /** Supplier is authoritative per order line, not per order. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'supplier_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_removed', false);
    }

    public function scopeRemoved(Builder $query): Builder
    {
        return $query->where('is_removed', true);
    }
}
