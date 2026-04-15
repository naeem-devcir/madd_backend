<?php

namespace App\Http\Requests\Api\Order;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Allow both authenticated customers and guests
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            // Store Information
            'vendor_store_id' => 'required|exists:vendor_stores,id',

            // Customer Information
            'customer_email' => 'required|email',
            'customer_firstname' => 'required|string|max:100',
            'customer_lastname' => 'required|string|max:100',
            'customer_phone' => 'nullable|string|max:20',

            // Cart Items
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:vendor_products,id',
            'items.*.sku' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',

            // Shipping Address
            'shipping_address' => 'required|array',
            'shipping_address.street' => 'required|string',
            'shipping_address.city' => 'required|string|max:100',
            'shipping_address.postcode' => 'required|string|max:20',
            'shipping_address.country_id' => 'required|string|size:2',
            'shipping_address.firstname' => 'required|string|max:100',
            'shipping_address.lastname' => 'required|string|max:100',

            // Billing Address
            'billing_address' => 'sometimes|array',
            'billing_address.street' => 'required_with:billing_address|string',
            'billing_address.city' => 'required_with:billing_address|string|max:100',
            'billing_address.postcode' => 'required_with:billing_address|string|max:20',
            'billing_address.country_id' => 'required_with:billing_address|string|size:2',

            // Payment & Shipping
            'payment_method' => 'required|string|in:stripe,paypal,credit_card,bank_transfer',
            'shipping_method' => 'required|string|max:100',

            // Coupon
            'coupon_code' => 'nullable|string|max:50|exists:coupons,code',

            // Customer Notes
            'customer_note' => 'nullable|string|max:1000',

            // Terms & Conditions
            'terms_accepted' => 'accepted',
            'privacy_accepted' => 'accepted',
        ];

        // For guest checkout, add guest token
        if (! $this->user()) {
            $rules['guest_token'] = 'required|string|min:32';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'vendor_store_id.required' => 'Store selection is required',
            'customer_email.required' => 'Email address is required',
            'customer_email.email' => 'Please enter a valid email address',
            'items.required' => 'Cart cannot be empty',
            'items.min' => 'At least one item is required',
            'shipping_address.required' => 'Shipping address is required',
            'payment_method.required' => 'Payment method is required',
            'shipping_method.required' => 'Shipping method is required',
            'terms_accepted.accepted' => 'You must accept the terms and conditions',
            'privacy_accepted.accepted' => 'You must accept the privacy policy',
            'guest_token.required' => 'Guest token is required for guest checkout',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Normalize email to lowercase
        if ($this->has('customer_email')) {
            $this->merge([
                'customer_email' => strtolower($this->customer_email),
            ]);
        }

        // Set billing address same as shipping if not provided
        if (! $this->has('billing_address') && $this->has('shipping_address')) {
            $this->merge([
                'billing_address' => $this->shipping_address,
            ]);
        }
    }
}
