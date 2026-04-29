<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ThemeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'preview_url' => $this->preview_url,
            'screenshot_url' => $this->screenshot_url,
            'category' => $this->category,
            'config_schema' => $this->config_schema,
            'is_active' => $this->is_active,
            'is_premium' => $this->is_premium,
            'price' => $this->price,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}