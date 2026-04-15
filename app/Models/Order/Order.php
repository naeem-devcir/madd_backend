<?php

namespace App\Models\Order;

use App\Models\Config\Coupon;
use App\Models\Config\Courier;
use App\Models\Financial\PaymentTransaction;
use App\Models\Financial\Refund;
use App\Models\Financial\Settlement;
use App\Models\Financial\Transaction;
use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

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

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function vendorStore()
    {
        return $this->belongsTo(VendorStore::class, 'vendor_store_id');
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function claimedBy()
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function parentOrder()
    {
        return $this->belongsTo(Order::class, 'parent_order_id');
    }

    public function childOrders()
    {
        return $this->hasMany(Order::class, 'parent_order_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id');
    }

    public function tracking()
    {
        return $this->hasOne(OrderTracking::class, 'order_id');
    }

    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'order_id');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class, 'order_id');
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'order_id');
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function carrier()
    {
        return $this->belongsTo(Courier::class, 'carrier_id');
    }
}
