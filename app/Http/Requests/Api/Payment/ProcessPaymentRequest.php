<?php

namespace App\Http\Requests\Api\Payment;

use Illuminate\Foundation\Http\FormRequest;

class ProcessPaymentRequest extends FormRequest
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
        $rules = [
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|string|in:stripe,paypal,credit_card,bank_transfer',
            'payment_token' => 'required_if:payment_method,stripe,paypal,credit_card|string',
            'save_payment_method' => 'boolean',
            'billing_address' => 'sometimes|array',
            'billing_address.street' => 'required_with:billing_address|string',
            'billing_address.city' => 'required_with:billing_address|string|max:100',
            'billing_address.postcode' => 'required_with:billing_address|string|max:20',
            'billing_address.country_id' => 'required_with:billing_address|string|size:2',
        ];

        // For credit card payments
        if ($this->payment_method === 'credit_card') {
            $rules['card_number'] = 'required|string|size:16';
            $rules['card_expiry_month'] = 'required|string|size:2|in:01,02,03,04,05,06,07,08,09,10,11,12';
            $rules['card_expiry_year'] = 'required|string|size:4|min:'.date('Y');
            $rules['card_cvv'] = 'required|string|size:3|max:4';
            $rules['card_holder_name'] = 'required|string|max:255';
        }

        // For bank transfer
        if ($this->payment_method === 'bank_transfer') {
            $rules['bank_reference'] = 'nullable|string|max:255';
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'order_id.required' => 'Order ID is required',
            'order_id.exists' => 'Invalid order',
            'payment_method.required' => 'Payment method is required',
            'payment_method.in' => 'Invalid payment method',
            'payment_token.required_if' => 'Payment token is required',
            'card_number.required' => 'Card number is required',
            'card_number.size' => 'Card number must be 16 digits',
            'card_expiry_month.required' => 'Card expiry month is required',
            'card_expiry_year.required' => 'Card expiry year is required',
            'card_cvv.required' => 'Card CVV is required',
            'card_holder_name.required' => 'Card holder name is required',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Remove spaces from card number
        if ($this->has('card_number')) {
            $this->merge([
                'card_number' => preg_replace('/\s+/', '', $this->card_number),
            ]);
        }
    }
}
