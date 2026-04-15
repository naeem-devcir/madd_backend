<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vendor_plans';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'setup_fee',
        'transaction_fee_percentage',
        'transaction_fee_fixed',
        'commission_rate',
        'max_products',
        'max_stores',
        'max_users',
        'bandwidth_limit_mb',
        'storage_limit_mb',
        'features',
        'is_active',
        'is_default',
        'sort_order',
        'trial_period_days',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_yearly' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'transaction_fee_percentage' => 'decimal:2',
        'transaction_fee_fixed' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'max_products' => 'integer',
        'max_stores' => 'integer',
        'max_users' => 'integer',
        'bandwidth_limit_mb' => 'integer',
        'storage_limit_mb' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'trial_period_days' => 'integer',
    ];

    // ========== Relationships ==========

    public function vendors()
    {
        return $this->hasMany(Vendor::class, 'plan_id', 'id');
    }

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    // ========== Accessors ==========

    public function getMonthlyPriceFormattedAttribute(): string
    {
        return number_format($this->price_monthly, 2).' '.config('app.currency', 'EUR');
    }

    public function getYearlyPriceFormattedAttribute(): string
    {
        return number_format($this->price_yearly, 2).' '.config('app.currency', 'EUR');
    }

    public function getHasTrialAttribute(): bool
    {
        return $this->trial_period_days > 0;
    }

    // ========== Methods ==========

    public function getFeatureValue(string $feature, $default = false)
    {
        return $this->features[$feature] ?? $default;
    }

    public function calculateMonthlyPriceWithTax(float $taxRate = 0): float
    {
        return $this->price_monthly * (1 + $taxRate / 100);
    }
}
