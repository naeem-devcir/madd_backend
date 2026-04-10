<?php

namespace App\Http\Requests\Api\Vendor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
        $user = $this->user();

        return [
            // Company Information
            'company_name' => 'sometimes|string|max:255',
            'legal_name' => 'sometimes|string|max:255',
            'trading_name' => 'nullable|string|max:255',
            'vat_number' => 'nullable|string|max:50|unique:vendors,vat_number,' . $vendor->id,
            'registration_number' => 'nullable|string|max:100',
            
            // Contact Information
            'phone' => 'nullable|string|max:20',
            'website' => 'nullable|url|max:255',
            'contact_email' => 'nullable|email|max:255',
            
            // Address
            'address_line1' => 'sometimes|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'sometimes|string|max:100',
            'postal_code' => 'sometimes|string|max:20',
            'country_code' => 'sometimes|string|size:2|exists:country_configs,code',
            
            // Business Details
            'description' => 'nullable|string|max:5000',
            'timezone' => 'sometimes|string|max:50|timezone',
            
            // Personal Information (User)
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            
            // Branding
            'logo' => 'nullable|image|max:2048|mimes:jpeg,png,jpg,gif,svg',
            'banner' => 'nullable|image|max:5120|mimes:jpeg,png,jpg,gif,svg',
            
            // Social Media
            'social_links' => 'nullable|array',
            'social_links.facebook' => 'nullable|url',
            'social_links.instagram' => 'nullable|url',
            'social_links.twitter' => 'nullable|url',
            'social_links.linkedin' => 'nullable|url',
            'social_links.youtube' => 'nullable|url',
            
            // Bank Account (if updating)
            'bank_account' => 'nullable|array',
            'bank_account.account_holder_name' => 'required_with:bank_account|string|max:255',
            'bank_account.iban' => 'required_with:bank_account|string|max:34',
            'bank_account.bic_swift' => 'required_with:bank_account|string|max:11',
            'bank_account.is_primary' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'vat_number.unique' => 'This VAT number is already registered',
            'website.url' => 'Please enter a valid website URL',
            'contact_email.email' => 'Please enter a valid contact email',
            'country_code.exists' => 'Selected country is not supported',
            'timezone.timezone' => 'Please select a valid timezone',
            'email.unique' => 'This email is already in use',
            'logo.image' => 'Logo must be an image file',
            'logo.max' => 'Logo size cannot exceed 2MB',
            'banner.image' => 'Banner must be an image file',
            'banner.max' => 'Banner size cannot exceed 5MB',
            'social_links.facebook.url' => 'Please enter a valid Facebook URL',
            'social_links.instagram.url' => 'Please enter a valid Instagram URL',
            'social_links.twitter.url' => 'Please enter a valid Twitter URL',
            'social_links.linkedin.url' => 'Please enter a valid LinkedIn URL',
            'social_links.youtube.url' => 'Please enter a valid YouTube URL',
            'bank_account.iban.required_with' => 'IBAN is required when updating bank account',
            'bank_account.bic_swift.required_with' => 'BIC/SWIFT is required when updating bank account',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'company_name' => 'company name',
            'legal_name' => 'legal name',
            'trading_name' => 'trading name',
            'vat_number' => 'VAT number',
            'registration_number' => 'registration number',
            'address_line1' => 'address',
            'postal_code' => 'postal code',
            'country_code' => 'country',
            'contact_email' => 'contact email',
            'first_name' => 'first name',
            'last_name' => 'last name',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize VAT number
        if ($this->has('vat_number')) {
            $this->merge([
                'vat_number' => strtoupper(trim($this->vat_number)),
            ]);
        }

        // Normalize country code to uppercase
        if ($this->has('country_code')) {
            $this->merge([
                'country_code' => strtoupper($this->country_code),
            ]);
        }

        // Normalize email to lowercase
        if ($this->has('email')) {
            $this->merge([
                'email' => strtolower(trim($this->email)),
            ]);
        }

        // Clean social media URLs
        if ($this->has('social_links')) {
            $socialLinks = $this->input('social_links', []);
            foreach ($socialLinks as $key => $url) {
                if ($url) {
                    $socialLinks[$key] = rtrim($url, '/');
                }
            }
            $this->merge(['social_links' => $socialLinks]);
        }
    }

    /**
     * Validate IBAN format.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Validate IBAN format if provided
            if ($this->has('bank_account.iban') && $this->bank_account['iban']) {
                $iban = str_replace(' ', '', strtoupper($this->bank_account['iban']));
                
                // Simple IBAN validation (length check)
                $validLengths = [
                    'AL' => 28, 'AD' => 24, 'AT' => 20, 'AZ' => 28, 'BH' => 22, 'BE' => 16, 'BA' => 20,
                    'BR' => 29, 'BG' => 22, 'CR' => 21, 'HR' => 21, 'CY' => 28, 'CZ' => 24, 'DK' => 18,
                    'DO' => 28, 'EE' => 20, 'FO' => 18, 'FI' => 18, 'FR' => 27, 'GE' => 22, 'DE' => 22,
                    'GI' => 23, 'GR' => 27, 'GL' => 18, 'GT' => 28, 'HU' => 28, 'IS' => 26, 'IE' => 22,
                    'IL' => 23, 'IT' => 27, 'JO' => 30, 'KZ' => 20, 'KW' => 30, 'LV' => 21, 'LB' => 28,
                    'LI' => 21, 'LT' => 20, 'LU' => 20, 'MT' => 31, 'MR' => 27, 'MU' => 30, 'MC' => 27,
                    'MD' => 24, 'ME' => 22, 'NL' => 18, 'NO' => 15, 'PK' => 24, 'PS' => 29, 'PL' => 28,
                    'PT' => 25, 'QA' => 29, 'RO' => 24, 'SM' => 27, 'SA' => 24, 'RS' => 22, 'SK' => 24,
                    'SI' => 19, 'ES' => 24, 'SE' => 24, 'CH' => 21, 'TN' => 24, 'TR' => 26, 'AE' => 23,
                    'GB' => 22, 'VG' => 24,
                ];
                
                $countryCode = substr($iban, 0, 2);
                $expectedLength = $validLengths[$countryCode] ?? null;
                
                if ($expectedLength && strlen($iban) !== $expectedLength) {
                    $validator->errors()->add(
                        'bank_account.iban',
                        "IBAN for {$countryCode} should be {$expectedLength} characters long"
                    );
                }
            }
        });
    }
}