<?php

namespace App\Models\Vendor;

use App\Models\Config\Domain;
use App\Models\Config\SalesPolicy;
use App\Models\Config\Theme;
use App\Models\Order\Order;
use App\Models\Product\VendorProduct;
use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorStore extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

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

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }

    public function theme()
    {
        return $this->belongsTo(Theme::class, 'theme_id');
    }

    public function salesPolicy()
    {
        return $this->belongsTo(SalesPolicy::class, 'sales_policy_id');
    }

    public function products()
    {
        return $this->hasMany(VendorProduct::class, 'vendor_store_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'vendor_store_id');
    }
}
