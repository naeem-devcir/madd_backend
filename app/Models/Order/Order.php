<?php

namespace App\Models\Order;

use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Models\Financial\Settlement;
use App\Models\Financial\Transaction;
use App\Models\Financial\PaymentTransaction;
use App\Models\Financial\Refund;
use App\Models\Config\Coupon;
use App\Models\Config\Courier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'orders';

    protected $fillable = [
        'uuid',
        'magento_order_id',
        'magento_order_increment_id',
        'parent_order_id',
        'vendor_id',
        'vendor_store_id',
        'customer_id',
        'customer_email',
        'customer_firstname',
        'customer_lastname',
        'customer_ip',
        'guest_token',
        'claimed_by_user_id',
        'claimed_at',
        'status',
        'payment_status',
        'fulfillment_status',
        'currency_code',
        'currency_rate',
        'subtotal',
        'tax_amount',
        'tax_rate',
        'shipping_amount',
        'discount_amount',
        'grand_total',
        'commission_amount',
        'commission_rate',
        'vendor_payout_amount',
        'payment_method',
        'payment_fee',
        'shipping_method',
        'carrier_id',
        'tracking_number',
        'coupon_code',
        'coupon_id',
        'source',
        'shipping_address',
        'billing_address',
        'customer_note',
        'admin_note',
        'shipped_at',
        'delivered_at',
        'settled_at',
        'settlement_id',
        'synced_at',
        'sync_status',
        'metadata',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'metadata' => 'array',
        'claimed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'settled_at' => 'datetime',
        'synced_at' => 'datetime',
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'shipping_amount' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'grand_total' => 'decimal:4',
        'commission_amount' => 'decimal:4',
        'vendor_payout_amount' => 'decimal:4',
        'payment_fee' => 'decimal:4',
        'commission_rate' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'currency_rate' => 'decimal:4',
    ];

    // ========== Relationships ==========
    
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }
    
    public function vendorStore()
    {
        return $this->belongsTo(VendorStore::class, 'vendor_store_id', 'uuid');
    }
    
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id', 'uuid');
    }
    
    public function claimedBy()
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id', 'uuid');
    }
    
    public function parentOrder()
    {
        return $this->belongsTo(Order::class, 'parent_order_id', 'id');
    }
    
    public function childOrders()
    {
        return $this->hasMany(Order::class, 'parent_order_id', 'id');
    }
    
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'uuid');
    }
    
    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id', 'uuid');
    }
    
    public function tracking()
    {
        return $this->hasOne(OrderTracking::class, 'order_id', 'uuid');
    }
    
    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'order_id', 'uuid');
    }
    
    public function successfulPayment()
    {
        return $this->hasOne(PaymentTransaction::class, 'order_id', 'uuid')->where('status', 'captured');
    }
    
    public function refunds()
    {
        return $this->hasMany(Refund::class, 'order_id', 'uuid');
    }
    
    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id', 'id');
    }
    
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'order_id', 'uuid');
    }
    
    public function coupon()
    {
        return $this->belongsTo(Coupon::class, 'coupon_id', 'id');
    }
    
    public function carrier()
    {
        return $this->belongsTo(Courier::class, 'carrier_id', 'uuid');
    }
    
    // ========== Scopes ==========
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }
    
    public function scopeShipped($query)
    {
        return $query->where('status', 'shipped');
    }
    
    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }
    
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
    
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }
    
    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }
    
    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
    
    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }
    
    public function scopeByStore($query, $storeId)
    {
        return $query->where('vendor_store_id', $storeId);
    }
    
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
    
    public function scopeNotSettled($query)
    {
        return $query->whereNull('settled_at');
    }
    
    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }
    
    public function scopePendingPayment($query)
    {
        return $query->where('payment_status', 'pending');
    }
    
    public function scopeGuestOrders($query)
    {
        return $query->whereNull('customer_id');
    }
    
    public function scopeClaimed($query)
    {
        return $query->whereNotNull('claimed_by_user_id');
    }
    
    // ========== Accessors ==========
    
    public function getOrderNumberAttribute(): string
    {
        return $this->magento_order_increment_id ?? 'MADD-' . str_pad($this->id, 8, '0', STR_PAD_LEFT);
    }
    
    public function getFormattedSubtotalAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->subtotal, 2);
    }
    
    public function getFormattedGrandTotalAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->grand_total, 2);
    }
    
    public function getIsPaidAttribute(): bool
    {
        return $this->payment_status === 'paid';
    }
    
    public function getIsPendingPaymentAttribute(): bool
    {
        return $this->payment_status === 'pending';
    }
    
    public function getIsRefundedAttribute(): bool
    {
        return $this->payment_status === 'refunded' || $this->status === 'refunded';
    }
    
    public function getIsChargebackAttribute(): bool
    {
        return $this->payment_status === 'chargeback';
    }
    
    public function getIsShippedAttribute(): bool
    {
        return !is_null($this->shipped_at);
    }
    
    public function getIsDeliveredAttribute(): bool
    {
        return !is_null($this->delivered_at);
    }
    
    public function getIsSettledAttribute(): bool
    {
        return !is_null($this->settled_at);
    }
    
    public function getIsGuestOrderAttribute(): bool
    {
        return is_null($this->customer_id);
    }
    
    public function getIsClaimedAttribute(): bool
    {
        return !is_null($this->claimed_by_user_id);
    }
    
    public function getTotalRefundedAmountAttribute(): float
    {
        return $this->refunds()->where('status', 'processed')->sum('refund_amount');
    }
    
    public function getRemainingAmountAttribute(): float
    {
        return $this->grand_total - $this->total_refunded_amount;
    }
    
    public function getShippingAddressFormattedAttribute(): string
    {
        $address = $this->shipping_address;
        $parts = [];
        
        if (!empty($address['street'])) {
            $parts[] = $address['street'];
        }
        if (!empty($address['city'])) {
            $parts[] = $address['city'];
        }
        if (!empty($address['postcode'])) {
            $parts[] = $address['postcode'];
        }
        if (!empty($address['country_id'])) {
            $parts[] = $address['country_id'];
        }
        
        return implode(', ', $parts);
    }
    
    // ========== Methods ==========
    
    public function updateStatus(string $status, ?string $notes = null, ?User $user = null): void
    {
        $oldStatus = $this->status;
        
        $this->status = $status;
        
        // Update fulfillment status based on order status
        if ($status === 'shipped') {
            $this->fulfillment_status = 'shipped';
            $this->shipped_at = now();
        } elseif ($status === 'delivered') {
            $this->fulfillment_status = 'delivered';
            $this->delivered_at = now();
        } elseif ($status === 'cancelled') {
            $this->fulfillment_status = 'cancelled';
        }
        
        $this->save();
        
        // Record status history
        OrderStatusHistory::create([
            'order_id' => $this->uuid,
            'status' => $status,
            'notes' => $notes,
            'changed_by' => $user?->uuid,
        ]);
        
        // Dispatch event
        event(new \App\Events\Order\OrderStatusChanged($this, $oldStatus, $status));
        
        // Send notification to customer
        if (!$this->is_guest_order || $this->customer) {
            \App\Jobs\Notification\SendOrderStatusNotification::dispatch($this, $status);
        }
    }
    
    public function updatePaymentStatus(string $status, ?string $notes = null): void
    {
        $oldStatus = $this->payment_status;
        
        $this->payment_status = $status;
        $this->save();
        
        // Record status history
        OrderStatusHistory::create([
            'order_id' => $this->uuid,
            'status' => 'payment_' . $status,
            'notes' => $notes,
            'changed_by' => auth()->user()?->uuid,
        ]);
        
        // If payment is successful, trigger order processing
        if ($status === 'paid' && $oldStatus !== 'paid') {
            event(new \App\Events\Payment\PaymentReceived($this));
        }
    }
    
    public function markAsShipped(string $trackingNumber, string $carrierId): void
    {
        $this->tracking_number = $trackingNumber;
        $this->carrier_id = $carrierId;
        $this->shipped_at = now();
        $this->status = 'shipped';
        $this->fulfillment_status = 'shipped';
        $this->save();
        
        OrderTracking::create([
            'order_id' => $this->uuid,
            'carrier_id' => $carrierId,
            'tracking_number' => $trackingNumber,
            'status' => 'shipped',
        ]);
        
        OrderStatusHistory::create([
            'order_id' => $this->uuid,
            'status' => 'shipped',
            'notes' => 'Order has been shipped with tracking number: ' . $trackingNumber,
        ]);
        
        // Send notification
        \App\Jobs\Notification\SendOrderShippedNotification::dispatch($this);
    }
    
    public function markAsDelivered(): void
    {
        $this->delivered_at = now();
        $this->status = 'delivered';
        $this->fulfillment_status = 'delivered';
        $this->save();
        
        OrderStatusHistory::create([
            'order_id' => $this->uuid,
            'status' => 'delivered',
            'notes' => 'Order has been delivered',
        ]);
        
        // Update tracking
        if ($this->tracking) {
            $this->tracking->update([
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);
        }
        
        // Send notification
        \App\Jobs\Notification\SendOrderDeliveredNotification::dispatch($this);
    }
    
    public function calculateCommission(): float
    {
        $vendor = $this->vendor;
        $commissionRate = $vendor->effective_commission_rate;
        
        $commissionAmount = $this->subtotal * ($commissionRate / 100);
        
        $this->commission_amount = $commissionAmount;
        $this->commission_rate = $commissionRate;
        $this->vendor_payout_amount = $this->subtotal - $commissionAmount - $this->payment_fee;
        $this->save();
        
        return $commissionAmount;
    }
    
    public function markAsSettled(Settlement $settlement): void
    {
        $this->settled_at = now();
        $this->settlement_id = $settlement->id;
        $this->save();
        
        // Create transaction record
        Transaction::create([
            'order_id' => $this->uuid,
            'settlement_id' => $settlement->uuid,
            'vendor_id' => $this->vendor_id,
            'type' => 'sale',
            'amount' => $this->vendor_payout_amount,
            'status' => 'completed',
            'currency_code' => $this->currency_code,
            'description' => 'Settlement for order #' . $this->order_number,
        ]);
    }
    
    public function canBeCancelled(): bool
    {
        return in_array($this->status, ['pending', 'processing']) && !$this->is_shipped;
    }
    
    public function canBeRefunded(): bool
    {
        return $this->payment_status === 'paid' && !$this->is_refunded && $this->status !== 'cancelled';
    }
    
    public function getTimeline(): array
    {
        $timeline = [];
        
        foreach ($this->statusHistory as $history) {
            $timeline[] = [
                'status' => $history->status,
                'notes' => $history->notes,
                'created_at' => $history->created_at->toIso8601String(),
                'by' => $history->changedBy?->full_name ?? 'System',
            ];
        }
        
        return $timeline;
    }
}
