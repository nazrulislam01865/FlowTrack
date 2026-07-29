<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FlowActivity extends Model
{
    protected $table = 'flow_activities';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit activity records are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit activity records are immutable.'));
    }

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function subject(): MorphTo { return $this->morphTo(); }
}
