<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    /**
     * Transform the review resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'magento_review_id' => $this->magento_review_id,

            // Rating
            'rating' => $this->rating,
            'rating_stars' => $this->rating_stars,
            'rating_percentage' => ($this->rating / 5) * 100,

            // Content
            'title' => $this->title,
            'body' => $this->body,
            'images' => $this->images,

            // Customer Info
            'customer' => $this->whenLoaded('customer', function () {
                return [
                    'id' => $this->customer->uuid,
                    'name' => $this->customer->full_name,
                    'avatar' => $this->customer->avatar_url,
                    'is_verified' => $this->customer->is_email_verified,
                ];
            }),
            'customer_name' => $this->customer?->full_name ?? 'Anonymous',

            // Product Info
            'product' => $this->whenLoaded('product', function () {
                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'sku' => $this->product->sku,
                ];
            }),

            // Vendor Info
            'vendor' => $this->whenLoaded('vendor', function () {
                return [
                    'id' => $this->vendor->uuid,
                    'name' => $this->vendor->company_name,
                ];
            }),
            'store' => $this->whenLoaded('store', function () {
                return [
                    'id' => $this->store->id,
                    'name' => $this->store->store_name,
                ];
            }),

            // Verification
            'verified_purchase' => (bool) $this->verified_purchased,
            'verified_badge' => $this->verified_purchased ? 'Verified Purchase' : null,

            // Social Proof
            'helpful_count' => $this->helpful_count,
            'is_helpful' => $this->when($request->user(), function () use ($request) {
                return $this->helpfulVotes()
                    ->where('user_id', $request->user()->id)
                    ->exists();
            }),

            // Status
            'status' => $this->status,
            'is_approved' => $this->is_approved,
            'is_pending' => $this->is_pending,
            'is_rejected' => $this->is_rejected,
            'is_flagged' => $this->is_flagged,

            // Vendor Response
            'vendor_response' => $this->vendor_response,
            'has_vendor_response' => $this->has_vendor_response,
            'vendor_response_at' => $this->vendor_response_at?->toIso8601String(),

            // Moderation
            'moderated_by' => $this->whenLoaded('moderatedBy', function () {
                return [
                    'id' => $this->moderatedBy->uuid,
                    'name' => $this->moderatedBy->full_name,
                ];
            }),
            'rejected_reason' => $this->rejected_reason,
            'moderated_at' => $this->moderated_at?->toIso8601String(),

            // Flags
            'flags_count' => $this->whenLoaded('flags', function () {
                return $this->flags->count();
            }),

            // Dates
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'time_ago' => $this->created_at?->diffForHumans(),
        ];
    }
}
