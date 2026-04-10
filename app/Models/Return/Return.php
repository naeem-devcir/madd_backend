<?php

namespace App\Models\Return;

use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Order\Order;
use App\Models\Config\Courier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Return extends Model
{
    use HasFactory;

    protected $table = 'returns';

    protected $fillable = [
        'rma_number',
        'order_id',
        'customer_id',
        'vendor_id',
        'status',
        'reason',
        'notes',
        'refund_amount',
        'courier_id',
        'tracking_number',
        'return_label_url',
        'received_at',
        'refunded_at',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:4',
        'received_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    // ========== Relationships ==========
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'uuid');
    }
    
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id', 'uuid');
    }
    
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }
    
    public function courier()
    {
        return $this->belongsTo(Courier::class, 'courier_id', 'uuid');
    }
    
    public function items()
    {
        return $this->hasMany(ReturnItem::class, 'return_id', 'uuid');
    }
    
    // ========== Scopes ==========
    
    public function scopeRequested($query)
    {
        return $query->where('status', 'requested');
    }
    
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
    
    public function scopeShipped($query)
    {
        return $query->where('status', 'shipped');
    }
    
    public function scopeReceived($query)
    {
        return $query->where('status', 'received');
    }
    
    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }
    
    // ========== Accessors ==========
    
    public function getRmaNumberAttribute($value): string
    {
        return $value ?? 'RMA-' . str_pad($this->id, 8, '0', STR_PAD_LEFT);
    }
    
    public function getIsRequestedAttribute(): bool
    {
        return $this->status === 'requested';
    }
    
    public function getIsApprovedAttribute(): bool
    {
        return $this->status === 'approved';
    }
    
    public function getIsShippedAttribute(): bool
    {
        return $this->status === 'shipped';
    }
    
    public function getIsReceivedAttribute(): bool
    {
        return $this->status === 'received';
    }
    
    public function getIsRefundedAttribute(): bool
    {
        return $this->status === 'refunded';
    }
    
    // ========== Methods ==========
    
    public function approve(): void
    {
        $this->status = 'approved';
        $this->save();
        
        // Generate return label
        if ($this->courier) {
            \App\Jobs\Return\GenerateReturnLabel::dispatch($this);
        }
    }
    
    public function reject(string $reason): void
    {
        $this->status = 'rejected';
        $this->notes = $reason;
        $this->save();
    }
    
    public function markAsShipped(string $trackingNumber): void
    {
        $this->status = 'shipped';
        $this->tracking_number = $trackingNumber;
        $this->save();
    }
    
    public function markAsReceived(): void
    {
        $this->status = 'received';
        $this->received_at = now();
        $this->save();
        
        // Process refund
        $this->processRefund();
    }
    
    protected function processRefund(): void
    {
        if ($this->refund_amount && $this->status === 'received') {
            // Process refund through payment gateway
            \App\Jobs\Payment\ProcessRefund::dispatch($this);
        }
    }
    
    public function completeRefund(string $refundReference): void
    {
        $this->status = 'refunded';
        $this->refunded_at = now();
        $this->save();
        
        // Update order status
        $this->order->updatePaymentStatus('refunded');
    }
}
