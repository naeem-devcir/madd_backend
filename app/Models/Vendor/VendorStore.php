<?php

namespace App\Models\Vendor;

use App\Models\Config\Domain;
use App\Models\Config\SalesPolicy;
use App\Models\Config\Theme;
use App\Models\Order\Order;
use App\Models\Product\VendorProduct;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class VendorStore extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'vendor_stores';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'store_name',
        'store_slug',
        'country_code',
        'language_code',
        'currency_code',
        'timezone',
        'status',
        'magento_store_id',
        'magento_store_group_id',
        'magento_website_id',
        'domain_id',
        'subdomain',
        'theme_id',
        'sales_policy_id',
        'logo_url',
        'favicon_url',
        'banner_url',
        'primary_color',
        'secondary_color',
        'contact_email',
        'contact_phone',
        'seo_meta_title',
        'seo_meta_description',
        'seo_settings',
        'payment_methods',
        'shipping_methods',
        'tax_settings',
        'social_links',
        'google_analytics_id',
        'facebook_pixel_id',
        'custom_css',
        'custom_js',
        'is_demo',
        'address',
        'metadata',
        'activated_at',
    ];

    protected $casts = [
        'seo_settings' => 'array',
        'payment_methods' => 'array',
        'shipping_methods' => 'array',
        'tax_settings' => 'array',
        'social_links' => 'array',
        'address' => 'array',
        'metadata' => 'array',
        'activated_at' => 'datetime',
        'is_demo' => 'boolean',
    ];

    // ========== Relationships ==========

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_id', 'id');
    }

    public function theme()
    {
        return $this->belongsTo(Theme::class, 'theme_id', 'id');
    }

    public function salesPolicy()
    {
        return $this->belongsTo(SalesPolicy::class, 'sales_policy_id', 'id');
    }

    public function products()
    {
        return $this->hasMany(VendorProduct::class, 'vendor_store_id', 'uuid');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'vendor_store_id', 'uuid');
    }

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeInactive($query)
    {
        return $query->where('status', 'inactive');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    public function scopeByCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    public function scopeBySubdomain($query, $subdomain)
    {
        return $query->where('subdomain', $subdomain);
    }

    public function scopeDemo($query)
    {
        return $query->where('is_demo', true);
    }

    // ========== Accessors ==========

    public function getStoreUrlAttribute(): string
    {
        if ($this->domain && $this->domain->is_primary && $this->domain->dns_verified) {
            return 'https://' . $this->domain->domain;
        }

        if ($this->subdomain) {
            return 'https://' . $this->subdomain . '.' . config('app.main_domain', 'madd.eu');
        }

        return url('/api/v1/stores/' . $this->store_slug);
    }

    public function getAdminUrlAttribute(): string
    {
        return url('/api/v1/vendor/stores/' . $this->id);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getIsInactiveAttribute(): bool
    {
        return $this->status === 'inactive';
    }

    public function getIsSuspendedAttribute(): bool
    {
        return $this->status === 'suspended';
    }

    public function getIsMaintenanceAttribute(): bool
    {
        return $this->status === 'maintenance';
    }

    public function getIsDemoAttribute(): bool
    {
        // return (bool) $this->is_demo;
        return $this->attributes['is_demo'] ?? 0;
    }

    public function getLogoUrlAttribute($value): ?string
    {
        if ($value) {
            return $value;
        }

        return $this->vendor->logo_url;
    }

    public function getPrimaryColorAttribute($value): string
    {
        return $value ?? '#4F46E5';
    }

    // ========== Methods ==========

    public function activate(): void
    {
        $this->status = 'active';
        $this->activated_at = now();
        $this->save();

        if (class_exists(\App\Jobs\Store\SyncStoreToMagento::class)) {
            \App\Jobs\Store\SyncStoreToMagento::dispatch($this);
        }
    }

    public function deactivate(): void
    {
        $this->status = 'inactive';
        $this->save();
    }

    public function suspend(): void
    {
        $this->status = 'suspended';
        $this->save();
    }

    public function enableMaintenance(): void
    {
        $this->status = 'maintenance';
        $this->save();
    }

    public function getPrimaryDomain(): ?string
    {
        if ($this->domain && $this->domain->is_primary && $this->domain->dns_verified) {
            return $this->domain->domain;
        }

        return null;
    }

    public function getAvailablePaymentMethods(): array
    {
        $policyMethods = $this->salesPolicy?->payment_methods ?? [];
        $storeMethods = $this->payment_methods ?? [];

        return array_intersect($policyMethods, $storeMethods);
    }

    public function getAvailableShippingMethods(): array
    {
        $policyMethods = $this->salesPolicy?->shipping_methods ?? [];
        $storeMethods = $this->shipping_methods ?? [];

        return array_intersect($policyMethods, $storeMethods);
    }

    public function getActiveThemeConfig(): array
    {
        if ($this->theme) {
            return $this->theme->config;
        }

        return Theme::where('is_default', true)->first()?->config ?? [];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
