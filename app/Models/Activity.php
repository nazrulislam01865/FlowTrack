<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'user_id',
        'event',
        'description',
        'meta',
    ];
    protected function casts(): array { return ['meta' => 'array']; }
    public function subject() { return $this->morphTo(); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
