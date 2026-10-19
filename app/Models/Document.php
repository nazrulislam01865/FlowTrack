<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    public const CATEGORY_ARTWORK_TRACKING_PDF = 'Artwork Tracking PDF';

    protected $fillable = [
        'document_number',
        'flow_job_id',
        'client_id',
        'task_id',
        'uploaded_by',
        'category',
        'name',
        'path',
        'mime_type',
        'size',
        'version',
        'is_final',
        'note',
    ];
    protected function casts(): array { return ['is_final' => 'boolean']; }
    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function task(): BelongsTo { return $this->belongsTo(Task::class, 'task_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
