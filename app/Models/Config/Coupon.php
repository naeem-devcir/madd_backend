<?php

namespace App\Models\Config;

use App\Models\Order\Order;
use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;


class Coupon extends Model
{
    use SoftDeletes;

    protected $table = 'coupons';

    protected $fillable = [
        'uuid',
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
        'is_active'
    ];

    protected $casts = [
        'allowed_emails' => 'array',
        'allowed_roles' => 'array',
        'combination_rules' => 'array',
        'applicable_ids' => 'array',
        'exclude_sale_items' => 'boolean',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'discount_value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'budget_limit' => 'decimal:2',
        'spent_amount' => 'decimal:2',
        'used_count' => 'integer',
        'max_uses' => 'integer',
        'vendor_id' => 'integer',
        'per_customer_limit' => 'integer',
        'usage_limit_per_transaction' => 'integer',
    ];

    protected $attributes = [
        'usage_limit_per_transaction' => 1,
        'sync_status' => 'pending',
        'is_active' => true,
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'coupon_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
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

    /**
     * Boot function to auto-generate UUID
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
