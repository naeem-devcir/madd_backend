<?php

namespace App\Models\Config;

use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesPolicy extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sales_policies';

    protected $fillable = [
        'country_code',
        'name',
        'payment_methods',
        'shipping_methods',
        'allowed_currencies',
        'tax_class',
        'min_order_amount',
        'guest_checkout_allowed',
        'return_window_days',
        'terms_url',
        'privacy_policy_url',
        'withdrawal_right_text',
        'is_active',
    ];

    protected $casts = [
        'payment_methods' => 'array',
        'shipping_methods' => 'array',
        'allowed_currencies' => 'array',
        'min_order_amount' => 'decimal:2',
        'guest_checkout_allowed' => 'boolean',
        'is_active' => 'boolean',
        'return_window_days' => 'integer',
    ];

    // ========== Relationships ==========
    
    public function country()
    {
        return $this->belongsTo(CountryConfig::class, 'country_code', 'code');
    }
    
    public function stores()
    {
        return $this->hasMany(VendorStore::class, 'sales_policy_id', 'id');
    }
    
    // ========== Scopes ==========
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeByCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }
    
    // ========== Accessors ==========
    
    public function getIsGuestCheckoutAllowedAttribute(): bool
    {
        return (bool) $this->guest_checkout_allowed;
    }
    
    public function getPaymentMethodsListAttribute(): array
    {
        return $this->payment_methods ?? [];
    }
    
    public function getShippingMethodsListAttribute(): array
    {
        return $this->shipping_methods ?? [];
    }
}