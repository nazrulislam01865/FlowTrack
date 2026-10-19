<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InquiryItem extends Model { protected $fillable = ['inquiry_id','item_name','category','quantity','unit','notes','unit_price','sort_order']; protected function casts(): array { return ['quantity'=>'decimal:2','unit_price'=>'decimal:2']; } public function inquiry(): BelongsTo { return $this->belongsTo(Inquiry::class); } }
