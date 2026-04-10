<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'magento_item_id' => $this->magento_item_id,
            'product_sku' => $this->product_sku,
            'product_name' => $this->product_name,
            'product_type' => $this->product_type,
            'quantity' => [
                'ordered' => $this->qty_ordered,
                'shipped' => $this->qty_shipped,
                'refunded' => $this->qty_refunded,
                'remaining' => $this->remaining_quantity,
            ],
            'price' => $this->price,
            'price_formatted' => $this->formatted_price,
            'tax_amount' => $this->tax_amount,
            'tax_rate' => $this->tax_rate,
            'discount_amount' => $this->discount_amount,
            'row_total' => $this->row_total,
            'row_total_formatted' => $this->formatted_row_total,
            'weight' => $this->weight,
            'product_options' => $this->product_options,
            'is_fully_shipped' => $this->is_fully_shipped,
            'is_fully_refunded' => $this->is_fully_refunded,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}