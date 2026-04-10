<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SocialLoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'access_token' => 'required|string',
            'provider_user_id' => 'sometimes|string',
            'provider_email' => 'sometimes|email',
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'avatar' => 'nullable|url|max:500',
            'user_type' => ['sometimes', Rule::in(['customer', 'vendor'])],
            'country_code' => 'sometimes|string|size:2',
            'locale' => 'sometimes|string|size:2',
            'device_name' => 'nullable|string|max:255',
            
            // Vendor specific fields
            'company_name' => 'required_if:user_type,vendor|string|max:255',
            'vat_number' => 'nullable|string|max:50',
            'address_line1' => 'required_if:user_type,vendor|string',
            'city' => 'required_if:user_type,vendor|string|max:100',
            'postal_code' => 'required_if:user_type,vendor|string|max:20',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'access_token.required' => 'Social access token is required',
            'provider_email.email' => 'Please provide a valid email address',
            'user_type.in' => 'Invalid user type. Must be customer or vendor',
            'company_name.required_if' => 'Company name is required for vendor registration',
            'address_line1.required_if' => 'Address is required for vendor registration',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize email to lowercase
        if ($this->has('provider_email')) {
            $this->merge([
                'provider_email' => strtolower($this->provider_email),
            ]);
        }
    }
}