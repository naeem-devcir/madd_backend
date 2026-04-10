<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'company_name' => $this->company_name,
            'company_slug' => $this->company_slug,
            'legal_name' => $this->legal_name,
            'vat_number' => $this->vat_number,
            'country_code' => $this->country_code,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'city' => $this->city,
                'postal_code' => $this->postal_code,
                'full' => $this->full_address,
            ],
            'contact' => [
                'email' => $this->contact_email,
                'phone' => $this->phone,
                'website' => $this->website,
            ],
            'logo_url' => $this->logo_url,
            'banner_url' => $this->banner_url,
            'description' => $this->description,
            'status' => $this->status,
            'kyc_status' => $this->kyc_status,
            'is_active' => $this->is_active,
            'is_kyc_verified' => $this->is_kyc_verified,
            'rating' => [
                'average' => $this->rating_average,
                'total' => $this->total_reviews,
            ],
            'financial' => [
                'current_balance' => $this->current_balance,
                'pending_balance' => $this->pending_balance,
                'total_earned' => $this->total_earned,
                'total_commission_paid' => $this->total_commission_paid,
                'commission_rate' => $this->effective_commission_rate,
            ],
            'plan' => $this->whenLoaded('plan', function() {
                return [
                    'name' => $this->plan->name,
                    'max_products' => $this->plan->max_products,
                    'max_stores' => $this->plan->max_stores,
                    'commission_rate' => $this->plan->commission_rate,
                    'expires_at' => $this->plan_ends_at?->toIso8601String(),
                    'is_expired' => $this->plan_is_expired,
                ];
            }),
            'stores' => VendorStoreResource::collection($this->whenLoaded('stores')),
            'bank_accounts' => $this->whenLoaded('bankAccounts', function() {
                return $this->bankAccounts->map(function($account) {
                    return [
                        'id' => $account->id,
                        'account_type' => $account->account_type,
                        'account_holder_name' => $account->account_holder_name,
                        'is_primary' => $account->is_primary,
                        'is_verified' => $account->is_verified,
                    ];
                });
            }),
            'stats' => [
                'total_products' => $this->whenCounted('products'),
                'total_orders' => $this->whenCounted('orders'),
                'total_stores' => $this->whenCounted('stores'),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}