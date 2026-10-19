<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowJobMember extends Model
{
    protected $table = 'flow_job_members';
    protected $fillable = [
        'flow_job_id',
        'user_id',
        'access_level',
        'can_manage_tasks',
        'can_upload_documents',
        'can_view_financials',
    ];
    protected function casts(): array
    {
        return [
            'can_manage_tasks' => 'boolean',
            'can_upload_documents' => 'boolean',
            'can_view_financials' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FlowJob::class, 'flow_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
