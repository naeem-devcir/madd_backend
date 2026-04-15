<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the product resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'uuid' => $this->uuid,
            'sku' => $this->sku,
            'magento_sku' => $this->magento_sku,
            'name' => $this->name,
            'type_id' => $this->type_id,
            'attribute_set_id' => $this->attribute_set_id,

            // Status
            'status' => $this->status,
            'is_active' => $this->is_active,
            'sync_status' => $this->sync_status,
            'is_synced' => $this->is_synced,

            // Vendor Info
            'vendor' => $this->whenLoaded('vendor', function () {
                return [
                    'id' => $this->vendor->uuid,
                    'name' => $this->vendor->company_name,
                    'slug' => $this->vendor->company_slug,
                ];
            }),
            'store' => $this->whenLoaded('store', function () {
                return new VendorStoreResource($this->store);
            }),

            // Pricing (from Magento - would be real-time in production)
            'price' => $this->price ?? null,
            'special_price' => $this->special_price ?? null,
            'formatted_price' => $this->when($this->price, function () {
                return $this->store?->currency_code.' '.number_format($this->price, 2);
            }),

            // Inventory (from Magento - would be real-time in production)
            'quantity' => $this->quantity ?? 0,
            'is_in_stock' => ($this->quantity ?? 0) > 0,

            // Product Details (from draft or Magento)
            'description' => $this->draft?->description ?? $this->description,
            'short_description' => $this->draft?->short_description,
            'weight' => $this->weight ?? $this->draft?->weight,

            // Media
            'images' => $this->draft?->media_gallery ?? $this->media_gallery ?? [],
            'main_image' => $this->getMainImageAttribute(),

            // Categories
            'categories' => $this->draft?->categories ?? $this->categories ?? [],

            // Attributes
            'attributes' => $this->draft?->attributes ?? $this->attributes ?? [],

            // SEO
            'seo' => $this->draft?->seo_data ?? $this->seo_data ?? [
                'meta_title' => $this->name,
                'meta_description' => substr($this->description ?? '', 0, 160),
            ],

            // Reviews & Ratings
            'rating' => [
                'average' => $this->rating_average ?? 0,
                'total' => $this->total_reviews ?? 0,
                'distribution' => $this->getRatingDistribution(),
            ],
            'reviews' => ReviewResource::collection($this->whenLoaded('reviews')),

            // Sales Stats
            'stats' => [
                'total_sold' => $this->total_sold ?? 0,
                'total_revenue' => $this->total_revenue ?? 0,
                'views_count' => $this->views_count ?? 0,
            ],

            // Draft Info (for pending products)
            'draft' => $this->whenLoaded('draft', function () {
                return [
                    'id' => $this->draft->uuid,
                    'version' => $this->draft->version,
                    'status' => $this->draft->status,
                    'submitted_at' => $this->draft->created_at?->toIso8601String(),
                ];
            }),

            // Sharing Info
            'is_shared' => $this->whenLoaded('sharing', function () {
                return $this->sharing->isNotEmpty();
            }),
            'shared_to_stores' => $this->whenLoaded('sharedToStores', function () {
                return $this->sharedToStores->map(function ($store) {
                    return [
                        'id' => $store->uuid,
                        'name' => $store->store_name,
                        'commission' => $store->pivot->commission_percentage,
                        'markup' => $store->pivot->markup_percentage,
                    ];
                });
            }),

            // URLs
            'url' => $this->product_url,
            'admin_url' => $this->admin_url,

            // Dates
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),

            // Metadata
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get main image attribute
     */
    private function getMainImageAttribute(): ?string
    {
        $images = $this->draft?->media_gallery ?? $this->media_gallery ?? [];

        if (! empty($images) && isset($images[0]['url'])) {
            return $images[0]['url'];
        }

        return null;
    }

    /**
     * Get rating distribution
     */
    private function getRatingDistribution(): array
    {
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        if ($this->relationLoaded('reviews')) {
            foreach ($this->reviews as $review) {
                if ($review->status === 'approved') {
                    $distribution[$review->rating]++;
                }
            }
        }

        return $distribution;
    }
}
