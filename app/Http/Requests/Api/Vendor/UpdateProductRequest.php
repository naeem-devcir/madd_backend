<?php

namespace App\Http\Requests\Api\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        
        if (!$user || !$user->vendor) {
            return false;
        }

        $product = $this->route('product');
        
        // Check if product belongs to this vendor
        return $product && $product->vendor_id === $user->vendor->id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $product = $this->route('product');
        
        return [
            'sku' => 'sometimes|string|max:255|unique:vendor_products,sku,' . ($product ? $product->id : 'NULL'),
            'name' => 'sometimes|string|max:500',
            'description' => 'nullable|string',
            'short_description' => 'nullable|string|max:500',
            'price' => 'sometimes|numeric|min:0',
            'special_price' => 'nullable|numeric|min:0',
            'special_price_from' => 'nullable|date|before_or_equal:special_price_to',
            'special_price_to' => 'nullable|date|after_or_equal:special_price_from',
            'quantity' => 'sometimes|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'status' => 'sometimes|string|in:active,inactive',
            'categories' => 'nullable|array',
            'categories.*' => 'string',
            'attributes' => 'nullable|array',
            'media_gallery' => 'nullable|array',
            'media_gallery.*.url' => 'required_with:media_gallery|url',
            'media_gallery.*.position' => 'integer|min:0',
            'media_gallery.*.delete' => 'boolean',
            'seo_data' => 'nullable|array',
            'seo_data.meta_title' => 'nullable|string|max:70',
            'seo_data.meta_description' => 'nullable|string|max:160',
            'seo_data.url_key' => 'nullable|string|max:255|regex:/^[a-z0-9-]+$/',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'sku.unique' => 'This SKU is already in use by another product',
            'price.min' => 'Price cannot be negative',
            'special_price.min' => 'Special price cannot be negative',
            'special_price_from.before_or_equal' => 'Special price start date must be before or equal to end date',
            'special_price_to.after_or_equal' => 'Special price end date must be after or equal to start date',
            'quantity.min' => 'Quantity cannot be negative',
            'weight.min' => 'Weight cannot be negative',
            'status.in' => 'Invalid product status',
            'seo_data.url_key.regex' => 'URL key can only contain lowercase letters, numbers, and hyphens',
            'seo_data.meta_title.max' => 'Meta title cannot exceed 70 characters',
            'seo_data.meta_description.max' => 'Meta description cannot exceed 160 characters',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'special_price_from' => 'special price start date',
            'special_price_to' => 'special price end date',
            'seo_data.meta_title' => 'meta title',
            'seo_data.meta_description' => 'meta description',
            'seo_data.url_key' => 'URL key',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize SKU to uppercase
        if ($this->has('sku')) {
            $this->merge([
                'sku' => strtoupper(trim($this->sku)),
            ]);
        }

        // Normalize URL key
        if ($this->has('seo_data.url_key')) {
            $urlKey = strtolower(trim($this->input('seo_data.url_key')));
            $urlKey = preg_replace('/[^a-z0-9-]+/', '-', $urlKey);
            $urlKey = preg_replace('/-+/', '-', $urlKey);
            $urlKey = trim($urlKey, '-');
            
            $this->merge([
                'seo_data' => array_merge($this->input('seo_data', []), [
                    'url_key' => $urlKey,
                ]),
            ]);
        }
    }

    /**
     * Validate special price dates.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check if special price is provided but dates are missing
            if ($this->has('special_price') && $this->special_price !== null) {
                if (!$this->has('special_price_from') && !$this->has('special_price_to')) {
                    // Dates are optional, but warn if both missing
                    // No error, just use indefinite special price
                }
            }

            // Check if special price is less than regular price
            if ($this->has('special_price') && $this->has('price') && $this->special_price >= $this->price) {
                $validator->errors()->add(
                    'special_price',
                    'Special price must be less than the regular price'
                );
            }
        });
    }
}