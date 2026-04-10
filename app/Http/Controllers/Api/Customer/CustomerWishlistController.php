<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer\Wishlist;
use App\Models\Product\VendorProduct;
use Illuminate\Http\Request;

class CustomerWishlistController extends Controller
{
    /**
     * Get customer wishlist
     */
    public function index(Request $request)
    {
        $customer = auth()->user();

        $wishlist = Wishlist::where('customer_id', $customer->id)
            ->with(['product', 'product.vendor', 'product.store'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $wishlist,
            'meta' => [
                'total' => Wishlist::where('customer_id', $customer->id)->count(),
            ]
        ]);
    }

    /**
     * Add product to wishlist
     */
    public function add($productId)
    {
        $customer = auth()->user();

        $product = VendorProduct::where('status', 'active')->findOrFail($productId);

        // Check if already in wishlist
        $exists = Wishlist::where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Product already in wishlist'
            ], 422);
        }

        $wishlistItem = Wishlist::create([
            'customer_id' => $customer->id,
            'product_id' => $productId,
            'store_id' => $product->vendor_store_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product added to wishlist',
            'data' => $wishlistItem
        ], 201);
    }

    /**
     * Remove product from wishlist
     */
    public function remove($productId)
    {
        $customer = auth()->user();

        $deleted = Wishlist::where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found in wishlist'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product removed from wishlist'
        ]);
    }

    /**
     * Clear entire wishlist
     */
    public function clear()
    {
        $customer = auth()->user();

        Wishlist::where('customer_id', $customer->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Wishlist cleared successfully'
        ]);
    }

    /**
     * Move wishlist item to cart
     */
    public function moveToCart($productId)
    {
        $customer = auth()->user();

        $wishlistItem = Wishlist::where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->first();

        if (!$wishlistItem) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found in wishlist'
            ], 404);
        }

        // Add to Magento cart via GraphQL (handled by frontend)
        // Just return product info for frontend to add to cart

        return response()->json([
            'success' => true,
            'message' => 'Product ready to add to cart',
            'data' => [
                'product_id' => $productId,
                'sku' => $wishlistItem->product->sku,
                'name' => $wishlistItem->product->name,
            ]
        ]);
    }
}