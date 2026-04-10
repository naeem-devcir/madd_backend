<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorStoreResource extends JsonResource
{
    /**
     * Transform the vendor store resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'store_name' => $this->store_name,
            'store_slug' => $this->store_slug,
            
            // Localization
            'country' => [
                'code' => $this->country_code,
                'name' => $this->getCountryNameAttribute(),
            ],
            'language' => [
                'code' => $this->language_code,
                'name' => $this->getLanguageNameAttribute(),
            ],
            'currency' => [
                'code' => $this->currency_code,
                'symbol' => $this->getCurrencySymbolAttribute(),
            ],
            'timezone' => $this->timezone,
            
            // URLs
            'urls' => [
                'storefront' => $this->store_url,
                'admin' => $this->admin_url,
                'logo' => $this->logo_url,
                'banner' => $this->banner_url,
                'favicon' => $this->favicon_url,
            ],
            
            // Branding
            'branding' => [
                'primary_color' => $this->primary_color,
                'secondary_color' => $this->secondary_color,
                'logo_url' => $this->logo_url,
                'banner_url' => $this->banner_url,
            ],
            
            // Contact
            'contact' => [
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
                'address' => $this->address,
            ],
            
            // Status
            'status' => $this->status,
            'status_label' => $this->getStatusLabelAttribute(),
            'is_active' => $this->is_active,
            'is_inactive' => $this->is_inactive,
            'is_suspended' => $this->is_suspended,
            'is_maintenance' => $this->is_maintenance,
            'is_demo' => $this->is_demo,
            
            // Domain
            'domain' => $this->whenLoaded('domain', function() {
                return [
                    'domain' => $this->domain->domain,
                    'is_primary' => $this->domain->is_primary,
                    'dns_verified' => $this->domain->dns_verified,
                    'ssl_status' => $this->domain->ssl_status,
                ];
            }),
            'subdomain' => $this->subdomain,
            'has_custom_domain' => !is_null($this->domain),
            
            // Theme
            'theme' => $this->whenLoaded('theme', function() {
                return [
                    'id' => $this->theme->id,
                    'name' => $this->theme->name,
                    'slug' => $this->theme->slug,
                    'is_premium' => $this->theme->is_premium,
                ];
            }),
            
            // Sales Policy
            'sales_policy' => $this->whenLoaded('salesPolicy', function() {
                return [
                    'id' => $this->salesPolicy->id,
                    'name' => $this->salesPolicy->name,
                    'return_window_days' => $this->salesPolicy->return_window_days,
                    'guest_checkout_allowed' => $this->salesPolicy->guest_checkout_allowed,
                ];
            }),
            
            // Configuration
            'config' => [
                'payment_methods' => $this->getAvailablePaymentMethods(),
                'shipping_methods' => $this->getAvailableShippingMethods(),
                'tax_settings' => $this->tax_settings,
                'social_links' => $this->social_links,
            ],
            
            // SEO
            'seo' => [
                'meta_title' => $this->seo_meta_title,
                'meta_description' => $this->seo_meta_description,
                'settings' => $this->seo_settings,
            ],
            
            // Analytics
            'analytics' => [
                'google_analytics_id' => $this->google_analytics_id,
                'facebook_pixel_id' => $this->facebook_pixel_id,
            ],
            
            // Customization
            'customization' => [
                'custom_css' => $this->custom_css,
                'custom_js' => $this->custom_js,
            ],
            
            // Magento Sync
            'magento' => [
                'store_id' => $this->magento_store_id,
                'store_group_id' => $this->magento_store_group_id,
                'website_id' => $this->magento_website_id,
            ],
            
            // Stats (when loaded)
            'stats' => [
                'products_count' => $this->whenCounted('products'),
                'orders_count' => $this->whenCounted('orders'),
                'total_revenue' => $this->when($this->orders_count, function() {
                    return $this->orders()->sum('grand_total');
                }),
            ],
            
            // Vendor Info
            'vendor' => $this->whenLoaded('vendor', function() {
                return [
                    'id' => $this->vendor->uuid,
                    'name' => $this->vendor->company_name,
                    'slug' => $this->vendor->company_slug,
                ];
            }),
            
            // Dates
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            
            // Metadata
            'metadata' => $this->metadata,
        ];
    }
    
    /**
     * Get status label
     */
    private function getStatusLabelAttribute(): string
    {
        $labels = [
            'active' => 'Active',
            'inactive' => 'Inactive',
            'suspended' => 'Suspended',
            'maintenance' => 'Under Maintenance',
        ];
        
        return $labels[$this->status] ?? ucfirst($this->status);
    }
    
    /**
     * Get country name
     */
    private function getCountryNameAttribute(): string
    {
        $countries = [
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'AT' => 'Austria',
            'CH' => 'Switzerland',
            'PL' => 'Poland',
            'SE' => 'Sweden',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'NO' => 'Norway',
            'IE' => 'Ireland',
            'PT' => 'Portugal',
            'GR' => 'Greece',
            'CZ' => 'Czech Republic',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'HR' => 'Croatia',
            'SK' => 'Slovakia',
            'SI' => 'Slovenia',
            'LT' => 'Lithuania',
            'LV' => 'Latvia',
            'EE' => 'Estonia',
        ];
        
        return $countries[$this->country_code] ?? $this->country_code;
    }
    
    /**
     * Get language name
     */
    private function getLanguageNameAttribute(): string
    {
        $languages = [
            'en' => 'English',
            'de' => 'German',
            'fr' => 'French',
            'it' => 'Italian',
            'es' => 'Spanish',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'no' => 'Norwegian',
        ];
        
        return $languages[$this->language_code] ?? $this->language_code;
    }
    
    /**
     * Get currency symbol
     */
    private function getCurrencySymbolAttribute(): string
    {
        $symbols = [
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'Fr',
            'SEK' => 'kr',
            'DKK' => 'kr',
            'NOK' => 'kr',
            'PLN' => 'zł',
            'CZK' => 'Kč',
            'HUF' => 'Ft',
        ];
        
        return $symbols[$this->currency_code] ?? $this->currency_code;
    }
}