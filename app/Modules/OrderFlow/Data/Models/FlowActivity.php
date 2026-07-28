<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
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

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function subject(): MorphTo { return $this->morphTo(); }
}
