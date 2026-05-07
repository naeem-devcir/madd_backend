<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminVendorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin') || $this->user()->hasRole('super_admin');
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:191|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:30',

            'company_name' => 'required|string|max:255',
            'company_slug' => 'nullable|string|max:255|regex:/^[a-z0-9-]+$/|unique:vendors,company_slug',
            'legal_name' => 'nullable|string|max:255',
            'trading_name' => 'nullable|string|max:255',
            'vat_number' => 'nullable|string|max:50',
            'registration_number' => 'nullable|string|max:100',
            'contact_email' => 'nullable|email|max:191',
            'website' => 'nullable|url|max:255',
            'country_code' => 'required|string|size:2',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:100',
            'postal_code' => 'required|string|max:20',
            'logo_url' => 'nullable|url|max:500',
            'banner_url' => 'nullable|url|max:500',
            'description' => 'nullable|string',

            'plan_id' => 'nullable|exists:vendor_plans,id',
            'plan_duration_months' => 'nullable|integer|min:1|max:36',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'commission_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'status' => ['nullable', Rule::in(['pending', 'active', 'suspended', 'terminated'])],
            'kyc_status' => ['nullable', Rule::in(['pending', 'verified', 'rejected'])],
            'timezone' => 'nullable|string|max:50',
            'metadata' => 'nullable|array',

            'magento_base_url' => 'nullable|url|max:255',
            'magento_admin_username' => 'nullable|string|max:255|required_with:magento_admin_pass',
            'magento_admin_pass' => 'nullable|string|required_with:magento_admin_username',
            'magento_access_token' => 'nullable|string',
            'magento_admin_token' => 'nullable|string',
            'magento_website_id' => 'nullable|integer',
            'magento_store_group_id' => 'nullable|integer',
            'magento_root_category_id' => 'nullable|integer',
        ];
    }
}
