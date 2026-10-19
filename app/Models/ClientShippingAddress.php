<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientShippingAddress extends Model
{
    protected $fillable = [
        'client_id',
        'label',
        'recipient',
        'address_line1',
        'suite',
        'city',
        'state',
        'zip',
        'country',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
