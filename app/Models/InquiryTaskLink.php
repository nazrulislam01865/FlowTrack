<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryTaskLink extends Model
{
    protected $fillable = [
        'inquiry_task_id',
        'created_by',
        'url',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(InquiryTask::class, 'inquiry_task_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
