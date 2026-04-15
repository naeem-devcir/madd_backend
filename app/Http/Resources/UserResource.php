<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'email' => $this->email,
            'phone' => $this->phone,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'avatar_url' => $this->avatar_url,
            'user_type' => $this->user_type,
            'status' => $this->status,
            'is_email_verified' => $this->is_email_verified,
            'is_phone_verified' => $this->is_phone_verified,
            'is_kyc_verified' => $this->is_kyc_verified,
            'country_code' => $this->country_code,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Relationships
            'vendor' => $this->whenLoaded('vendor', function () {
                return new VendorResource($this->vendor);
            }),
            'mlm_agent' => $this->whenLoaded('mlmAgent', function () {
                return [
                    'id' => $this->mlmAgent->id,
                    'level' => $this->mlmAgent->level,
                    'status' => $this->mlmAgent->status,
                    'total_commissions' => $this->mlmAgent->total_commissions_earned,
                ];
            }),
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->pluck('name');
            }),
            'permissions' => $this->whenLoaded('permissions', function () {
                return $this->getPermissionArray();
            }),
        ];
    }
}
