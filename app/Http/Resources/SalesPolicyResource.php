<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'country' => $this->whenLoaded('country', function() {
                return [
                    'iso2' => $this->country->iso2,
                    'name' => $this->country->name,
                ];
            }),
            'name' => $this->name,
            'payment_methods' => $this->payment_methods,
            'shipping_methods' => $this->shipping_methods,
            'allowed_currencies' => $this->allowed_currencies,
            'tax_class' => $this->tax_class,
            'min_order_amount' => $this->min_order_amount,
            'guest_checkout_allowed' => $this->guest_checkout_allowed,
            'return_window_days' => $this->return_window_days,
            'terms_url' => $this->terms_url,
            'privacy_policy_url' => $this->privacy_policy_url,
            'withdrawal_right_text' => $this->withdrawal_right_text,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}