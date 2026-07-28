<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowJobMember extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'can_manage_tasks' => 'boolean',
            'can_upload_documents' => 'boolean',
            'can_view_financials' => 'boolean',
        ];
    }

    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
