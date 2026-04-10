<?php

namespace App\Http\Requests\Api\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->vendor;
    }

    public function rules(): array
    {
        return [
            'vendor_store_id' => 'required|exists:vendor_stores,id',
            'sku' => 'required|string|max:255|unique:vendor_products,sku',
            'name' => 'required|string|max:500',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'special_price' => 'nullable|numeric|min:0',
            'special_price_from' => 'nullable|date',
            'special_price_to' => 'nullable|date|after:special_price_from',
            'quantity' => 'required|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'categories' => 'nullable|array',
            'categories.*' => 'string',
            'attributes' => 'nullable|array',
            'media_gallery' => 'nullable|array|max:10',
            'media_gallery.*.url' => 'required|url',
            'media_gallery.*.position' => 'integer|min:0',
            'seo_data' => 'nullable|array',
            'seo_data.meta_title' => 'nullable|string|max:70',
            'seo_data.meta_description' => 'nullable|string|max:160',
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_store_id.required' => 'Store selection is required',
            'sku.required' => 'Product SKU is required',
            'sku.unique' => 'This SKU is already in use',
            'name.required' => 'Product name is required',
            'price.required' => 'Product price is required',
            'price.min' => 'Price cannot be negative',
            'quantity.required' => 'Initial quantity is required',
        ];
    }
}