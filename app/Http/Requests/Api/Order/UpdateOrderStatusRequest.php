<?php

namespace App\Http\Requests\Api\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $order = $this->route('order');

        // Admin can update any order status
        if ($user->hasRole('admin') || $user->hasRole('super_admin')) {
            return true;
        }

        // Vendor can update their own orders
        if ($user->vendor && $order && $order->vendor_id === $user->vendor->id) {
            return in_array($this->status, ['processing', 'shipped', 'cancelled']);
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $order = $this->route('order');
        $currentStatus = $order ? $order->status : null;

        return [
            'status' => [
                'required',
                'string',
                Rule::in(['pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded']),
                function ($attribute, $value, $fail) use ($currentStatus) {
                    if ($currentStatus === 'cancelled' && $value !== 'refunded') {
                        $fail('Cannot change status of a cancelled order.');
                    }
                    if ($currentStatus === 'delivered' && !in_array($value, ['completed', 'refunded'])) {
                        $fail('Delivered orders can only be marked as completed or refunded.');
                    }
                    if ($currentStatus === 'shipped' && !in_array($value, ['delivered', 'cancelled'])) {
                        $fail('Shipped orders can only be marked as delivered or cancelled.');
                    }
                },
            ],
            'notes' => 'nullable|string|max:1000',
            'notify_customer' => 'boolean',
            
            // For shipped status
            'tracking_number' => 'required_if:status,shipped|nullable|string|max:100',
            'carrier_id' => 'required_if:status,shipped|nullable|exists:couriers,id',
            
            // For cancelled status
            'cancellation_reason' => 'required_if:status,cancelled|nullable|string|max:500',
            
            // For refunded status
            'refund_amount' => 'required_if:status,refunded|nullable|numeric|min:0',
            'refund_reason' => 'required_if:status,refunded|nullable|string|max:500',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Order status is required',
            'status.in' => 'Invalid order status',
            'tracking_number.required_if' => 'Tracking number is required when marking as shipped',
            'carrier_id.required_if' => 'Carrier selection is required when marking as shipped',
            'cancellation_reason.required_if' => 'Cancellation reason is required',
            'refund_amount.required_if' => 'Refund amount is required',
            'refund_reason.required_if' => 'Refund reason is required',
        ];
    }
}