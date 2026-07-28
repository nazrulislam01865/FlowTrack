<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowDocumentVersion extends Model
{
    protected $table = 'flow_document_versions';
    protected $guarded = [];

    public function document(): BelongsTo { return $this->belongsTo(FlowDocument::class, 'flow_document_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
