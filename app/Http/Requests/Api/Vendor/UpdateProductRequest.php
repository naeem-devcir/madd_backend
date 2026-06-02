<?php

namespace App\Http\Requests\Api\Vendor\Product;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'quantity' => 'sometimes|integer|min:0',
            'status' => 'sometimes|boolean',
            'visibility' => 'sometimes|integer|in:1,2,3,4',
            'weight' => 'nullable|numeric|min:0',
            'tax_class_id' => 'nullable|integer',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string',
            'url_key' => 'nullable|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_keyword' => 'nullable|string',
            'meta_description' => 'nullable|string|max:500',
            'special_price' => 'nullable|numeric|min:0',
            'special_from_date' => 'nullable|date',
            'special_to_date' => 'nullable|date|after:special_from_date',
            'cost' => 'nullable|numeric|min:0',
            'manage_stock' => 'boolean',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer',
            'media' => 'nullable|array',
            'product_links' => 'nullable|array',
            'custom_options' => 'nullable|array',
            'tier_prices' => 'nullable|array',
            'inventory' => 'nullable|array',
            'dynamic_attributes' => 'nullable|array',
        ];
    }
}