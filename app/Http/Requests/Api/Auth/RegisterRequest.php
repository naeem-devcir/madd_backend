<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'user_type' => ['required', Rule::in(['customer', 'vendor'])],
            'country_code' => 'required|string|size:2',
            'locale' => 'nullable|string|size:2',
            'marketing_opt_in' => 'boolean',
            
            // Vendor specific fields
            'company_name' => 'required_if:user_type,vendor|string|max:255',
            'vat_number' => 'nullable|string|max:50',
            'address_line1' => 'required_if:user_type,vendor|string',
            'city' => 'required_if:user_type,vendor|string|max:100',
            'postal_code' => 'required_if:user_type,vendor|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Email address is required',
            'email.unique' => 'This email is already registered',
            'password.min' => 'Password must be at least 8 characters',
            'password.confirmed' => 'Password confirmation does not match',
            'user_type.required' => 'Please select account type',
            'user_type.in' => 'Invalid account type selected',
        ];
    }
}