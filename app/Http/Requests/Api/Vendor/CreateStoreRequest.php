<?php

namespace App\Http\Requests\Api\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() && $this->user()->vendor !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $vendor = $this->user()->vendor;

        return [
            'store_name' => 'required|string|max:255',
            'country_code' => 'required|string|size:2|exists:country_configs,code',
            'currency_code' => 'required|string|size:3|exists:currencies,code',
            'language_code' => 'required|string|size:2|exists:languages,code',
            'subdomain' => [
                'nullable',
                'string',
                'max:100',
                'alpha_dash',
                Rule::unique('vendor_stores', 'subdomain'),
            ],
            'domain' => [
                'nullable',
                'string',
                'max:253',
                'regex:/^(?!:\/\/)([a-zA-Z0-9-]+\.)+[a-zA-Z]{2,}$/',
                Rule::unique('domains', 'domain'),
            ],
            'logo' => 'nullable|image|max:2048|mimes:jpeg,png,jpg,gif,svg',
            'banner' => 'nullable|image|max:5120|mimes:jpeg,png,jpg,gif,svg',
            'primary_color' => 'nullable|string|regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
            'secondary_color' => 'nullable|string|regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'payment_methods' => 'nullable|array',
            'payment_methods.*' => 'string|in:stripe,paypal,credit_card,bank_transfer,cash_on_delivery',
            'shipping_methods' => 'nullable|array',
            'shipping_methods.*' => 'string',
            'is_demo' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'store_name.required' => 'Store name is required',
            'country_code.required' => 'Country is required',
            'country_code.exists' => 'Selected country is not supported',
            'currency_code.required' => 'Currency is required',
            'language_code.required' => 'Language is required',
            'subdomain.unique' => 'This subdomain is already taken',
            'domain.unique' => 'This domain is already in use',
            'domain.regex' => 'Please enter a valid domain name',
            'logo.image' => 'Logo must be an image file',
            'logo.max' => 'Logo size cannot exceed 2MB',
            'banner.image' => 'Banner must be an image file',
            'banner.max' => 'Banner size cannot exceed 5MB',
            'primary_color.regex' => 'Primary color must be a valid hex code',
            'secondary_color.regex' => 'Secondary color must be a valid hex code',
            'contact_email.email' => 'Please enter a valid contact email',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'store_name' => 'store name',
            'country_code' => 'country',
            'currency_code' => 'currency',
            'language_code' => 'language',
            'contact_email' => 'contact email',
            'contact_phone' => 'contact phone',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize country code to uppercase
        if ($this->has('country_code')) {
            $this->merge([
                'country_code' => strtoupper($this->country_code),
            ]);
        }

        // Normalize currency code to uppercase
        if ($this->has('currency_code')) {
            $this->merge([
                'currency_code' => strtoupper($this->currency_code),
            ]);
        }

        // Normalize language code to lowercase
        if ($this->has('language_code')) {
            $this->merge([
                'language_code' => strtolower($this->language_code),
            ]);
        }
    }

    /**
     * Validate if vendor can create more stores based on plan.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $vendor = $this->user()->vendor;
            $currentStoreCount = $vendor->stores()->count();
            $maxStores = $vendor->plan->max_stores;

            if ($currentStoreCount >= $maxStores) {
                $validator->errors()->add(
                    'store_limit',
                    "You have reached your plan's store limit of {$maxStores} stores. Please upgrade your plan to add more stores."
                );
            }
        });
    }
}