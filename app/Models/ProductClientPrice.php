<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Private client selling-price overrides; never part of the supplier bridge payload. */
class ProductClientPrice extends Model
{
    protected $fillable = ['workspace_id', 'product_id', 'client_id', 'price_table_raw', 'price_breakpoints'];

    protected function casts(): array
    {
        return ['price_breakpoints' => 'array'];
    }

    public function product(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'product_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}
