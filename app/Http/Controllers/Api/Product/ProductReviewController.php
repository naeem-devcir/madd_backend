<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Product\VendorProduct;
use App\Models\Review\Review;
use App\Models\Review\ReviewHelpfulVote;
use App\Models\Review\ReviewFlag;
use App\Services\Review\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\log;



class ProductReviewController extends Controller
{
    protected $reviewService;

    public function __construct(ReviewService $reviewService)
    {
        $this->reviewService = $reviewService;
    }

    /**
     * Get reviews for a product
     */
    public function index(Request $request, $productId)
    {
        $request->validate([
            'rating' => 'nullable|integer|min:1|max:5',
            'sort_by' => 'nullable|in:newest,oldest,highest_rating,lowest_rating,most_helpful',
            'per_page' => 'nullable|integer|min:1|max:50',
            'with_images' => 'boolean',
        ]);


        


        $product = VendorProduct::findOrFail($productId);
        $query = Review::where('vendor_product_id', $productId)
            ->where('status', 'approved');
            // ->with(['customer', 'helpfulVotes']);

        // Apply rating filter
        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        // Apply images filter
        if ($request->boolean('with_images')) {
            $query->whereNotNull('images');
            $query->whereRaw('JSON_LENGTH(images) > 0');
        }

        // Apply sorting
        switch ($request->sort_by) {
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'highest_rating':
                $query->orderBy('rating', 'desc');
                break;
            case 'lowest_rating':
                $query->orderBy('rating', 'asc');
                break;
            case 'most_helpful':
                $query->orderBy('helpful_count', 'desc');
                break;
            default:
                $query->orderBy('created_at', 'desc');
        }

        $reviews = $query->paginate($request->get('per_page', 20));

        // Get review statistics
        $stats = $this->getReviewStatistics($productId);

        return response()->json([
            'success' => true,
            'data' => [
                'product' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                ],
                'statistics' => $stats,
                'reviews' => ReviewResource::collection($reviews),
                'meta' => [
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                    'total' => $reviews->total(),
                    'per_page' => $reviews->perPage(),
                ]
            ]
        ]);
    }

    /**
     * Get review statistics for a product
     */
    public function statistics($productId)
    {
        $product = VendorProduct::findOrFail($productId);

        $stats = $this->getReviewStatistics($productId);

        return response()->json([
            'success' => true,
            'data' => [
                'product_id' => $productId,
                'product_name' => $product->name,
                'statistics' => $stats,
            ]
        ]);
    }

    /**
     * Backward-compatible summary endpoint alias.
     */
    public function summary($productId)
    {
        return $this->statistics($productId);
    }

    /**
     * Get a single review
     */
    public function show($productId, $reviewId)
    {
        $review = Review::where('vendor_product_id', $productId)
            ->where('id', $reviewId)
            ->where('status', 'approved')
            ->with(['customer', 'helpfulVotes', 'vendorResponse'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new ReviewResource($review)
        ]);
    }

    /**
     * Get reviews by customer (for customer panel)
     */
    public function myReviews(Request $request)
    {
        $customer = auth()->user();

        $reviews = Review::where('customer_id', $customer->uuid)
            ->with(['product', 'vendor', 'store'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => ReviewResource::collection($reviews),
            'meta' => [
                'total' => Review::where('customer_id', $customer->uuid)->count(),
                'approved' => Review::where('customer_id', $customer->uuid)->where('status', 'approved')->count(),
                'pending' => Review::where('customer_id', $customer->uuid)->where('status', 'pending')->count(),
            ]
        ]);
    }

    /**
     * Submit a review (authenticated customer)
     */
    public function store(Request $request, $productId)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'body' => 'required|string|min:10|max:5000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'url|max:500',
            'order_id' => 'required|exists:orders,id',
        ]);

        $customer = auth()->user();
        $product = VendorProduct::findOrFail($productId);

        // Verify customer purchased this product
        $hasPurchased = $this->verifyPurchase($customer->uuid, $productId, $request->order_id);

        if (!$hasPurchased) {
            return response()->json([
                'success' => false,
                'message' => 'You can only review products you have purchased and received'
            ], 403);
        }

        // Check if already reviewed
        $existingReview = Review::where('customer_id', $customer->uuid)
            ->where('vendor_product_id', $productId)
            ->first();

        if ($existingReview) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this product',
                'data' => [
                    'review_id' => $existingReview->id,
                    'status' => $existingReview->status,
                ]
            ], 422);
        }

        DB::beginTransaction();

        try {
            $review = Review::create([
                'customer_id' => $customer->uuid,
                'vendor_product_id' => $productId,
                'vendor_id' => $product->vendor_id,
                'vendor_store_id' => $product->vendor_store_id,
                'magento_product_id' => $product->magento_product_id,
                'rating' => $request->rating,
                'title' => $request->title,
                'body' => $request->body,
                'images' => $request->images,
                'verified_purchased' => true,
                'status' => 'pending',
                'language_code' => app()->getLocale(),
            ]);

            DB::commit();

            // Notify admin about new review
            \App\Jobs\Notification\SendNewReviewNotification::dispatch($review);

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully and pending moderation',
                'data' => [
                    'review_id' => $review->id,
                    'status' => $review->status,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a review
     */
    public function update(Request $request, $productId, $reviewId)
    {
        $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'body' => 'sometimes|string|min:10|max:5000',
            'images' => 'nullable|array|max:5',
        ]);

        $customer = auth()->user();

        $review = Review::where('id', $reviewId)
            ->where('customer_id', $customer->uuid)
            ->where('status', 'pending')
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $review->update($request->only(['rating', 'title', 'body', 'images']));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully',
                'data' => new ReviewResource($review)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a review
     */
    public function destroy($productId, $reviewId)
    {
        $customer = auth()->user();

        $review = Review::where('id', $reviewId)
            ->where('customer_id', $customer->uuid)
            ->whereIn('status', ['pending', 'rejected'])
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $review->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark review as helpful
     */
    public function markHelpful($productId, $reviewId)
    {
        $customer = auth()->user();

        $review = Review::where('id', $reviewId)
            ->where('status', 'approved')
            ->firstOrFail();

        // Check if already voted
        $existingVote = ReviewHelpfulVote::where('review_id', $reviewId)
            ->where('user_id', $customer->id)
            ->first();

        if ($existingVote) {
            return response()->json([
                'success' => false,
                'message' => 'You have already voted on this review'
            ], 422);
        }

        DB::beginTransaction();

        try {
            ReviewHelpfulVote::create([
                'review_id' => $reviewId,
                'user_id' => $customer->id,
                'is_helpful' => true,
            ]);

            $review->increment('helpful_count');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review marked as helpful',
                'data' => [
                    'helpful_count' => $review->helpful_count,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Report review as inappropriate
     */
    public function report(Request $request, $productId, $reviewId)
    {
        $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $customer = auth()->user();

        $review = Review::findOrFail($reviewId);

        // Check if already reported by this user
        $existingReport = ReviewFlag::where('review_id', $reviewId)
            ->where('user_id', $customer->id)
            ->exists();

        if ($existingReport) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reported this review'
            ], 422);
        }

        DB::beginTransaction();

        try {
            ReviewFlag::create([
                'review_id' => $reviewId,
                'user_id' => $customer->id,
                'reason' => $request->reason,
                'status' => 'pending',
            ]);

            $review->increment('reported_count');

            // Auto-flag if multiple reports
            if ($review->reported_count >= 3) {
                $review->status = 'flagged';
                $review->save();
                
                // Notify admin about flagged review
                \App\Jobs\Notification\SendFlaggedReviewNotification::dispatch($review);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review has been reported to moderators'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to report review',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get review statistics helper
     */
    private function getReviewStatistics($productId): array
    {
        $reviews = Review::where('vendor_product_id', $productId)
            ->where('status', 'approved');

        $total = $reviews->count();
        
        if ($total === 0) {
            return [
                'total' => 0,
                'average_rating' => 0,
                'rating_distribution' => [
                    1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0
                ],
                'with_images' => 0,
                'verified_purchases' => 0,
            ];
        }

        $average = $reviews->avg('rating');
        
        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $distribution[$i] = $reviews->where('rating', $i)->count();
        }

        return [
            'total' => $total,
            'average_rating' => round($average, 1),
            'rating_distribution' => $distribution,
            'with_images' => $reviews->whereNotNull('images')->count(),
            'verified_purchases' => $reviews->where('verified_purchased', true)->count(),
        ];
    }

    /**
     * Verify customer purchased the product
     */
    private function verifyPurchase($customerId, $productId, $orderId): bool
    {
        $order = \App\Models\Order\Order::find($orderId);

        if (!$order) {
            return false;
        }

        return \App\Models\Order\OrderItem::where('order_id', $order->uuid)
            ->whereHas('order', function($query) use ($customerId) {
                $query->where('customer_id', $customerId)
                    ->where('status', 'delivered');
            })
            ->where('vendor_product_id', $productId)
            ->exists();
    }
}
