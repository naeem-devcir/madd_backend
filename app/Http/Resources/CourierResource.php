<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'api_type' => $this->api_type,
            'countries' => $this->countries,
            'service_levels' => $this->service_levels,
            'tracking_url_template' => $this->tracking_url_template,
            'logo_url' => $this->logo_url,
            'support_contact' => $this->support_contact,
            'settlement_contact' => $this->settlement_contact,
            'weight_limit_kg' => $this->weight_limit_kg,
            'insurance_options' => $this->insurance_options,
            'data_processing_agreement' => $this->data_processing_agreement,
            'contract_reference' => $this->contract_reference,
            'settlement_due_day' => $this->settlement_due_day,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}