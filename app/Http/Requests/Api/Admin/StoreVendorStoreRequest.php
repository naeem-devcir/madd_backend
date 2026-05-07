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
            // Required
            'vendor_id'  => 'required|integer|exists:vendors,id',
            'store_name' => 'required|string|max:255',
            'store_slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                // Unique per vendor — two vendors can have the same slug
                Rule::unique('vendor_stores', 'store_slug')
                    ->where('vendor_id', $this->input('vendor_id')),
            ],

            // Localization
            'country_code'  => 'required|string|size:2',
            'language_code' => 'sometimes|string|size:2|in:en,es,fr,de,it,pt,nl,pl,tr,ar,zh,ja',
            'currency_code' => 'sometimes|string|size:3|in:EUR,USD,GBP,JPY,CNY,AUD,CAD,CHF',
            'timezone'      => 'sometimes|string|max:100',

            // Domain
            'subdomain' => [
                'nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('vendor_stores', 'subdomain'),
            ],
            'domain'    => 'nullable|string|max:255|unique:domains,domain',
            'domain_id' => 'nullable|exists:domains,id',

            // Theme & Policy
            'theme_id'        => 'nullable|exists:themes,id',
            'sales_policy_id' => 'nullable|exists:sales_policies,id',

            // Status
            'status' => 'sometimes|in:inactive,active,suspended,maintenance',

            // Branding
            'primary_color'   => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'secondary_color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'logo_url'        => 'nullable|url|max:500',
            'favicon_url'     => 'nullable|url|max:500',
            'banner_url'      => 'nullable|url|max:500',

            // Contact
            'contact_email' => 'nullable|email|max:191',
            'contact_phone' => 'nullable|string|max:20',

            // SEO
            'seo_meta_title'       => 'nullable|string|max:255',
            'seo_meta_description' => 'nullable|string|max:500',
            'seo_settings'         => 'nullable|array',

            // Settings
            'payment_methods'  => 'nullable|array',
            'shipping_methods' => 'nullable|array',
            'tax_settings'     => 'nullable|array',
            'social_links'     => 'nullable|array',

            // Analytics
            'google_analytics_id' => 'nullable|string|max:50',
            'facebook_pixel_id'   => 'nullable|string|max:50',

            // Custom code
            'custom_css' => 'nullable|string',
            'custom_js'  => 'nullable|string',

            // Misc
            'is_demo'  => 'sometimes|boolean',
            'address'  => 'nullable|array',
            'metadata' => 'nullable|array',

            // Magento overrides (admin can pre-set these if already exists in Magento)
            'magento_store_id'       => 'nullable|integer',
            'magento_store_group_id' => 'nullable|integer',
            'magento_website_id'     => 'nullable|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'store_slug.regex'  => 'Store slug must only contain lowercase letters, numbers, and hyphens.',
            'store_slug.unique' => 'This slug is already taken by another store for this vendor.',
            'subdomain.regex'   => 'Subdomain must only contain lowercase letters, numbers, and hyphens.',
            'subdomain.unique'  => 'This subdomain is already taken.',
            'vendor_id.required' => 'Please select a vendor for this store.',
        ];
    }
}