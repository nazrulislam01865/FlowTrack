<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderCollection extends Model
{
    protected $table = 'flow_job_collections';
    protected $fillable = [
        'flow_job_id',
        'collection_owner_id',
        'last_follow_up_at',
        'next_follow_up_at',
        'latest_note',
    ];

    protected function casts(): array
    {
        return [
            'last_follow_up_at' => 'date',
            'next_follow_up_at' => 'date',
        ];
    }

    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'collection_owner_id'); }
    public function updates(): HasMany { return $this->hasMany(CollectionUpdate::class, 'flow_job_collection_id')->latest('id'); }
}
