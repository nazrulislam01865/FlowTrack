<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowTaskComment extends Model
{
    protected $table = 'flow_task_comments';
    protected $guarded = [];

    public function task(): BelongsTo { return $this->belongsTo(FlowTask::class, 'flow_task_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
