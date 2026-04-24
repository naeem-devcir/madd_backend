<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin') || $this->user()->hasRole('super_admin');
    }

    public function rules(): array
    {
        return [
            // Required fields
            'vendor_id' => 'required|exists:vendors,id',
            'store_name' => 'required|string|max:255',
            'store_slug' => 'required|string|max:255|regex:/^[a-z0-9-]+$/',
            
            // Localization
            'country_code' => 'required|string|size:2',
            'language_code' => 'sometimes|string|size:2|in:en,es,fr,de,it,pt,nl,pl,tr,ar,zh,ja',
            'currency_code' => 'sometimes|string|size:3|in:EUR,USD,GBP,JPY,CNY,AUD,CAD,CHF',
            'timezone' => 'sometimes|string|max:100',
            
            // Domain & Subdomain - Updated for your enum values
            'subdomain' => 'nullable|string|max:100|unique:vendor_stores,subdomain|regex:/^[a-z0-9-]+$/',
            'domain' => 'nullable|string|regex:/^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/|unique:domains,domain',
            'domain_id' => 'nullable|exists:domains,id',
            
            // Theme
            'theme_id' => 'nullable|exists:themes,id',
            
            // Status
            'status' => 'sometimes|in:inactive,active,suspended,maintenance',
            
            // Colors & Branding
            'primary_color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'secondary_color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'logo_url' => 'nullable|url|max:500',
            'favicon_url' => 'nullable|url|max:500',
            'banner_url' => 'nullable|url|max:500',
            
            // Contact Information
            'contact_email' => 'nullable|email|max:191',
            'contact_phone' => 'nullable|string|max:20',
            
            // SEO
            'seo_meta_title' => 'nullable|string|max:255',
            'seo_meta_description' => 'nullable|string|max:500',
            'seo_settings' => 'nullable|array',
            
            // Settings
            'payment_methods' => 'nullable|array',
            'shipping_methods' => 'nullable|array',
            'tax_settings' => 'nullable|array',
            'social_links' => 'nullable|array',
            
            // Analytics
            'google_analytics_id' => 'nullable|string|max:50',
            'facebook_pixel_id' => 'nullable|string|max:50',
            
            // Custom Code
            'custom_css' => 'nullable|string',
            'custom_js' => 'nullable|string',
            
            // Metadata
            'address' => 'nullable|array',
            'metadata' => 'nullable|array',
            
            // Sales Policy
            'sales_policy_id' => 'nullable|exists:sales_policies,id',
            
            // Magento Integration
            'magento_store_id' => 'nullable|integer',
            'magento_store_group_id' => 'nullable|integer',
            'magento_website_id' => 'nullable|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'store_slug.regex' => 'The store slug must only contain lowercase letters, numbers, and hyphens.',
            'subdomain.regex' => 'The subdomain must only contain lowercase letters, numbers, and hyphens.',
            'subdomain.unique' => 'This subdomain is already taken.',
            'vendor_id.required' => 'Please select a vendor for this store.',
            'domain.regex' => 'Please enter a valid domain name (e.g., example.com)',
            'domain.unique' => 'This domain is already registered.',
        ];
    }
}