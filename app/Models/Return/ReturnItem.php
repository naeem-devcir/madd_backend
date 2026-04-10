<?php

namespace App\Models\Return;

use App\Models\Order\OrderItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnItem extends Model
{
    use HasFactory;

    protected $table = 'return_items';

    protected $fillable = [
        'return_id',
        'order_item_id',
        'quantity',
        'reason',
        'condition',
        'refund_amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'refund_amount' => 'decimal:4',
    ];

    // ========== Relationships ==========
    
    public function return()
    {
        return $this->belongsTo(Return::class, 'return_id', 'uuid');
    }
    
    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id', 'uuid');
    }
    
    // ========== Accessors ==========
    
    public function getFormattedRefundAmountAttribute(): string
    {
        return $this->return->order->currency_code . ' ' . number_format($this->refund_amount, 2);
    }
}
