<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Review\Review;
use App\Models\Review\ReviewHelpfulVote;
use App\Models\Review\ReviewFlag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerReviewController extends Controller
{
    /**
     * Get customer reviews
     */
    public function index(Request $request)
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
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'total' => $reviews->total(),
            ]
        ]);
    }

    /**
     * Create a review
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:vendor_products,id',
            'order_id' => 'required|exists:orders,id',
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'body' => 'required|string|min:10|max:5000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'url',
        ]);

        $customer = auth()->user();

        // Verify customer purchased this product
        $product = \App\Models\Product\VendorProduct::findOrFail($request->product_id);
        $order = Order::findOrFail($request->order_id);

        $hasPurchased = OrderItem::where('order_id', $order->uuid)
            ->whereHas('order', function($query) use ($customer) {
                $query->where('customer_id', $customer->uuid)
                    ->where('status', 'delivered');
            })
            ->where('vendor_product_id', $request->product_id)
            ->exists();

        if (!$hasPurchased) {
            return response()->json([
                'success' => false,
                'message' => 'You can only review products you have purchased and received'
            ], 403);
        }

        // Check if already reviewed
        $existingReview = Review::where('customer_id', $customer->uuid)
            ->where('vendor_product_id', $request->product_id)
            ->exists();

        if ($existingReview) {
            return response()->json([
                'success' => false,
                'message' => 'You have already reviewed this product'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $review = Review::create([
                'customer_id' => $customer->uuid,
                'vendor_product_id' => $request->product_id,
                'vendor_id' => $product->vendor_id,
                'vendor_store_id' => $product->vendor_store_id,
                'magento_product_id' => $product->magento_product_id,
                'rating' => $request->rating,
                'title' => $request->title,
                'body' => $request->body,
                'images' => $request->images,
                'verified_purchased' => true,
                'status' => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully and pending moderation',
                'data' => new ReviewResource($review)
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
    public function update(Request $request, $id)
    {
        $customer = auth()->user();

        $review = Review::where('customer_id', $customer->uuid)
            ->where('status', 'pending')
            ->findOrFail($id);

        $request->validate([
            'rating' => 'sometimes|integer|min:1|max:5',
            'title' => 'nullable|string|max:255',
            'body' => 'sometimes|string|min:10|max:5000',
            'images' => 'nullable|array|max:5',
        ]);

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
    public function destroy($id)
    {
        $customer = auth()->user();

        $review = Review::where('customer_id', $customer->uuid)
            ->whereIn('status', ['pending', 'rejected'])
            ->findOrFail($id);

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
    public function markHelpful($id)
    {
        $customer = auth()->user();

        $review = Review::where('status', 'approved')->findOrFail($id);

        // Check if already voted
        $existingVote = ReviewHelpfulVote::where('review_id', $id)
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
                'review_id' => $id,
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
     * Flag review as inappropriate
     */
    public function flag(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $customer = auth()->user();

        $review = Review::findOrFail($id);

        // Check if already flagged by this user
        $existingFlag = ReviewFlag::where('review_id', $id)
            ->where('user_id', $customer->id)
            ->exists();

        if ($existingFlag) {
            return response()->json([
                'success' => false,
                'message' => 'You have already flagged this review'
            ], 422);
        }

        DB::beginTransaction();

        try {
            ReviewFlag::create([
                'review_id' => $id,
                'user_id' => $customer->id,
                'reason' => $request->reason,
                'status' => 'pending',
            ]);

            $review->increment('reported_count');

            // Auto-flag if multiple reports
            if ($review->reported_count >= 3) {
                $review->status = 'flagged';
                $review->save();
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
                'message' => 'Failed to flag review',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
