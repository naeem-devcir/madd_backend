<?php

namespace App\Services\Review;

use App\Models\Review\Review;
use App\Models\Product\VendorProduct;
use Illuminate\Support\Facades\Cache;

class ReviewService
{
    /**
     * Calculate product rating statistics
     */
    public function calculateProductRating($productId): array
    {
        $cacheKey = "product_rating_{$productId}";
        
        return Cache::remember($cacheKey, 3600, function () use ($productId) {
            $reviews = Review::where('vendor_product_id', $productId)
                ->where('status', 'approved');

            $total = $reviews->count();
            
            if ($total === 0) {
                return [
                    'average' => 0,
                    'total' => 0,
                    'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                    'percentage' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
                ];
            }

            $distribution = [];
            for ($i = 1; $i <= 5; $i++) {
                $distribution[$i] = $reviews->where('rating', $i)->count();
            }

            $percentage = [];
            foreach ($distribution as $rating => $count) {
                $percentage[$rating] = round(($count / $total) * 100, 1);
            }

            return [
                'average' => round($reviews->avg('rating'), 1),
                'total' => $total,
                'distribution' => $distribution,
                'percentage' => $percentage,
            ];
        });
    }

    /**
     * Clear product rating cache
     */
    public function clearProductRatingCache($productId): void
    {
        Cache::forget("product_rating_{$productId}");
    }

    /**
     * Get recent reviews for product
     */
    public function getRecentReviews($productId, $limit = 5)
    {
        return Review::where('vendor_product_id', $productId)
            ->where('status', 'approved')
            ->with(['customer'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get reviews with images
     */
    public function getReviewsWithImages($productId, $limit = 10)
    {
        return Review::where('vendor_product_id', $productId)
            ->where('status', 'approved')
            ->whereNotNull('images')
            ->whereRaw('JSON_LENGTH(images) > 0')
            ->with(['customer'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get highest rated products
     */
    public function getHighestRatedProducts($vendorId = null, $limit = 10)
    {
        $query = VendorProduct::where('status', 'active');
        
        if ($vendorId) {
            $query->where('vendor_id', $vendorId);
        }
        
        return $query->where('total_reviews', '>', 0)
            ->orderBy('rating_average', 'desc')
            ->orderBy('total_reviews', 'desc')
            ->limit($limit)
            ->get();
    }
}