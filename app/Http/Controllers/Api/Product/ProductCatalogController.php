<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Models\Product\VendorProduct;
use App\Models\Vendor\VendorStore;
use App\Services\Product\ProductCatalogService;
use Illuminate\Http\Request;

class ProductCatalogController extends Controller
{
    protected $catalogService;

    public function __construct(ProductCatalogService $catalogService)
    {
        $this->catalogService = $catalogService;
    }

    /**
     * Get products for a store
     */
    public function index(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'category' => 'nullable|string',
            'min_price' => 'nullable|numeric',
            'max_price' => 'nullable|numeric',
            'sort_by' => 'nullable|in:price_asc,price_desc,newest,popular',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        // Find the store first
        $store = VendorStore::where('id', $request->store_id)->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
                'store_id' => $request->store_id
            ], 404);
        }
        
        // Check if store is active
        if ($store->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'This store is currently not active',
                'store_id' => $store->id,
                'store_name' => $store->store_name,
                'current_status' => $store->status
            ], 403);
        }

        // Get products from Magento via GraphQL (this is a proxy)
        // For now, we'll query our reference table
        $query = VendorProduct::where('vendor_store_id', $store->uuid)
            ->where('status', 'active')
            ->where('sync_status', 'synced');

        // Apply filters
        if ($request->has('category')) {
            // This would need category mapping
            // $query->whereJsonContains('categories', $request->category);
        }

        if ($request->has('min_price')) {
            // Price would come from Magento, not local
        }

        // Sorting
        switch ($request->sort_by) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'newest':
                $query->orderBy('created_at', 'desc');
                break;
            default:
                $query->orderBy('created_at', 'desc');
        }

        $products = $query->paginate($request->get('per_page', 20));

        // For actual implementation, this should query Magento GraphQL
        // and return real-time data including inventory, prices, etc.

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                    'slug' => $store->store_slug,
                ],
                'products' => $products,
                'filters' => [
                    'categories' => $this->getCategories($store),
                    'price_range' => $this->getPriceRange($store),
                ],
            ]
        ]);
    }

    /**
     * Get single product
     */
    public function show($storeSlug, $productSlug)
    {
        $store = VendorStore::where('store_slug', $storeSlug)->first();
        
        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
                'store_slug' => $storeSlug
            ], 404);
        }
        
        if ($store->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'This store is currently not active',
                'store_name' => $store->store_name
            ], 403);
        }

        // This should query Magento GraphQL for real-time product data
        // For now, return product reference
        $product = VendorProduct::where('vendor_store_id', $store->uuid)
            ->where(function($query) use ($productSlug) {
                $query->where('sku', $productSlug)
                    ->orWhere('slug', $productSlug);
            })
            ->first();
            
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
                'product_slug' => $productSlug
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                ],
                'product' => $product,
                'reviews' => $product->reviews()
                    ->where('status', 'approved')
                    ->with('customer')
                    ->paginate(10),
            ]
        ]);
    }

    /**
     * Get product by SKU (for API integration)
     */
    public function showBySku($sku)
    {
        $product = VendorProduct::where('sku', $sku)
            ->where('status', 'active')
            ->with(['vendor', 'store'])
            ->first();
            
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
                'sku' => $sku
            ], 404);
        }
        
        // Also check if the store is active
        if ($product->store && $product->store->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Product is not available because the store is inactive',
                'store_name' => $product->store->store_name
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $product
        ]);
    }

    /**
     * Get related products
     */
    public function related($productId)
    {
        $product = VendorProduct::find($productId);
        
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
                'product_id' => $productId
            ], 404);
        }
        
        if ($product->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Product is not active'
            ], 403);
        }

        // Get products from same category or vendor
        $related = VendorProduct::where('vendor_id', $product->vendor_id)
            ->where('id', '!=', $productId)
            ->where('status', 'active')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $related
        ]);
    }

    /**
     * Get store categories
     */
    private function getCategories($store)
    {
        // This should come from Magento
        return [
            ['id' => 1, 'name' => 'Electronics', 'slug' => 'electronics'],
            ['id' => 2, 'name' => 'Clothing', 'slug' => 'clothing'],
            ['id' => 3, 'name' => 'Home & Garden', 'slug' => 'home-garden'],
        ];
    }

    /**
     * Get price range for filtering
     */
    private function getPriceRange($store)
    {
        // This should come from Magento
        return [
            'min' => 0,
            'max' => 1000,
        ];
    }
}