<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iso2' => $this->iso2,
            'iso3' => $this->iso3,
            'phone_code' => $this->phone_code,
            'currency_code' => $this->currency_code,
            'region' => $this->region,
            'subregion' => $this->subregion,
            'capital' => $this->capital,
            'flag' => $this->flag,
            'is_active' => $this->is_active,
            'config' => $this->whenLoaded('config', function() {
                return [
                    'id' => $this->config?->id,
                    'eu_member' => $this->config?->eu_member,
                    'currency_symbol' => $this->config?->currency_symbol,
                    'tax_rate' => $this->config?->tax_rate,
                    'timezone' => $this->config?->timezone,
                    'language_codes' => $this->config?->language_codes,
                    'madd_company_id' => $this->config?->madd_company_id,
                    'is_active' => $this->config?->is_active,
                ];
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}