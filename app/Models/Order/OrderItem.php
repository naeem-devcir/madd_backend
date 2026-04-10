<?php

namespace App\Models\Order;

use App\Models\Product\VendorProduct;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'magento_item_id',
        'vendor_product_id',
        'magento_product_id',
        'magento_sku',
        'vendor_sku',
        'product_sku',
        'product_name',
        'product_type',
        'weight',
        'qty_ordered',
        'qty_shipped',
        'qty_refunded',
        'fulfilled_qty',
        'price',
        'tax_amount',
        'tax_rate',
        'discount_amount',
        'row_total',
    ];

    protected $casts = [
        'qty_ordered' => 'decimal:4',
        'qty_shipped' => 'decimal:4',
        'qty_refunded' => 'decimal:4',
        'fulfilled_qty' => 'decimal:4',
        'price' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'tax_rate' => 'decimal:2',
        'discount_amount' => 'decimal:4',
        'row_total' => 'decimal:4',
        'weight' => 'decimal:4',
    ];

    // ========== Relationships ==========
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'uuid');
    }
    
    public function vendorProduct()
    {
        return $this->belongsTo(VendorProduct::class, 'vendor_product_id', 'id');
    }
    
    // ========== Scopes ==========
    
    public function scopeShipped($query)
    {
        return $query->where('qty_shipped', '>', 0);
    }
    
    public function scopeNotShipped($query)
    {
        return $query->where('qty_shipped', 0);
    }
    
    public function scopeRefunded($query)
    {
        return $query->where('qty_refunded', '>', 0);
    }
    
    // ========== Accessors ==========
    
    public function getRemainingQuantityAttribute(): float
    {
        return $this->qty_ordered - $this->qty_shipped - $this->qty_refunded;
    }
    
    public function getIsFullyShippedAttribute(): bool
    {
        return $this->qty_shipped >= $this->qty_ordered;
    }
    
    public function getIsFullyRefundedAttribute(): bool
    {
        return $this->qty_refunded >= $this->qty_ordered;
    }
    
    public function getFormattedPriceAttribute(): string
    {
        return $this->order->currency_code . ' ' . number_format($this->price, 2);
    }
    
    public function getFormattedRowTotalAttribute(): string
    {
        return $this->order->currency_code . ' ' . number_format($this->row_total, 2);
    }
    
    // ========== Methods ==========
    
    public function markAsShipped(float $quantity): void
    {
        $this->qty_shipped += $quantity;
        $this->save();
        
        // Update order fulfillment status if all items shipped
        if ($this->order->items()->whereRaw('qty_ordered > qty_shipped')->count() === 0) {
            $this->order->updateStatus('shipped', 'All items have been shipped');
        }
    }
    
    public function markAsRefunded(float $quantity): void
    {
        $this->qty_refunded += $quantity;
        $this->save();
    }
    
    public function getTotalRevenue(): float
    {
        return $this->row_total;
    }
}
