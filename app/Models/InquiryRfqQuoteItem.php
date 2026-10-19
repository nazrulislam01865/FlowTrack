<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryRfqQuoteItem extends Model
{
    protected $fillable = ['quote_id','inquiry_item_id','product_name','quantity','unit_price','moq','sort_order'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price' => 'decimal:4', 'moq' => 'decimal:2', 'sort_order' => 'integer'];
    }

    public function quote(): BelongsTo { return $this->belongsTo(InquiryRfqQuote::class, 'quote_id'); }
    public function inquiryItem(): BelongsTo { return $this->belongsTo(InquiryItem::class, 'inquiry_item_id'); }
}
