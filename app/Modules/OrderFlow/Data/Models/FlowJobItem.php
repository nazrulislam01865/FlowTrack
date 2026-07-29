<?php

namespace App\Modules\OrderFlow\Data\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowJobItem extends Model
{
    protected $table = 'flow_job_items';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'product_id');
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(MasterRecord::class, 'product_category_id');
    }
}
