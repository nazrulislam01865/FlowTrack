<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionUpdate extends Model
{
    protected $fillable = [
        'flow_job_collection_id',
        'actor_id',
        'follow_up_date',
        'next_follow_up_at',
        'note',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_date' => 'date',
            'next_follow_up_at' => 'date',
        ];
    }

    public function collection(): BelongsTo { return $this->belongsTo(OrderCollection::class, 'flow_job_collection_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
