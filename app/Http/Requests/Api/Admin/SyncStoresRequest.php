<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncStoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vendor_uuid' => 'required|string|exists:vendors,uuid'
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_uuid.required' => 'Vendor UUID is required',
            'vendor_uuid.exists' => 'The specified vendor does not exist'
        ];
    }
}