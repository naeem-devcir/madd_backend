<?php

namespace App\Models\Config;

use App\Models\Order\Order;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'coupons';

    protected $fillable = [
        'code',
        'description',
        'type',
        'vendor_id',
        'discount_type',
        'discount_value',
        'min_order_amount',
        'max_uses',
        'used_count',
        'usage_limit_per_transaction',
        'per_customer_limit',
        'exclude_sale_items',
        'allowed_emails',
        'allowed_roles',
        'combination_rules',
        'budget_limit',
        'spent_amount',
        'applicable_to',
        'applicable_ids',
        'starts_at',
        'expires_at',
        'magento_rule_id',
        'magento_coupon_id',
        'sync_status',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:4',
        'min_order_amount' => 'decimal:4',
        'budget_limit' => 'decimal:4',
        'spent_amount' => 'decimal:4',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'usage_limit_per_transaction' => 'integer',
        'per_customer_limit' => 'integer',
        'exclude_sale_items' => 'boolean',
        'allowed_emails' => 'array',
        'allowed_roles' => 'array',
        'combination_rules' => 'array',
        'applicable_ids' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // ========== Relationships ==========

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'coupon_id', 'id');
    }

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            });
    }

    public function scopePlatform($query)
    {
        return $query->where('type', 'platform');
    }

    public function scopeVendor($query)
    {
        return $query->where('type', 'vendor');
    }

    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
        });
    }

    // ========== Accessors ==========

    public function getIsValidAttribute(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses && $this->used_count >= $this->max_uses) {
            return false;
        }

        if ($this->budget_limit && $this->spent_amount >= $this->budget_limit) {
            return false;
        }

        return true;
    }

    public function getDiscountDisplayAttribute(): string
    {
        if ($this->discount_type === 'percentage') {
            return $this->discount_value.'% OFF';
        } elseif ($this->discount_type === 'fixed_amount') {
            return '€'.number_format($this->discount_value, 2).' OFF';
        } elseif ($this->discount_type === 'free_shipping') {
            return 'Free Shipping';
        }

        return 'Buy X Get Y';
    }

    // ========== Methods ==========

    public function calculateDiscount(float $subtotal): float
    {
        if ($subtotal < $this->min_order_amount) {
            return 0;
        }

        switch ($this->discount_type) {
            case 'percentage':
                return $subtotal * ($this->discount_value / 100);
            case 'fixed_amount':
                return min($this->discount_value, $subtotal);
            case 'free_shipping':
                return 0; // Shipping discount handled separately
            default:
                return 0;
        }
    }

    public function incrementUsage(float $discountAmount): void
    {
        $this->increment('used_count');
        $this->increment('spent_amount', $discountAmount);
    }

    public function canBeUsedBy(string $email, ?int $customerId = null): bool
    {
        // Check email restriction
        if (! empty($this->allowed_emails) && ! in_array($email, $this->allowed_emails)) {
            return false;
        }

        // Check per customer limit
        if ($customerId && $this->per_customer_limit) {
            $usedCount = Order::where('coupon_id', $this->id)
                ->where('customer_id', $customerId)
                ->count();

            if ($usedCount >= $this->per_customer_limit) {
                return false;
            }
        }

        return true;
    }

    public function markAsSynced(int $magentoRuleId, int $magentoCouponId): void
    {
        $this->magento_rule_id = $magentoRuleId;
        $this->magento_coupon_id = $magentoCouponId;
        $this->sync_status = 'synced';
        $this->save();
    }

    public function markSyncFailed(): void
    {
        $this->sync_status = 'failed';
        $this->save();
    }
}
