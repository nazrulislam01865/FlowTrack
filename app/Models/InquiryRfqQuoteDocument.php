<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryRfqQuoteDocument extends Model
{
    protected $fillable = ['quote_id', 'document_type', 'name', 'path', 'mime_type', 'size', 'sort_order'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'sort_order' => 'integer'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(InquiryRfqQuote::class, 'quote_id');
    }
}
