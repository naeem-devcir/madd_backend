<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Models\Vendor\VendorStore;
use App\Services\Product\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductCategoryController extends Controller
{
    protected $categoryService;

    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * Get all categories for a store
     */
    public function index(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'parent_id' => 'nullable|integer',
            'include_products_count' => 'boolean',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        // Cache categories for better performance
        $cacheKey = "store_categories_{$store->id}_" . ($request->parent_id ?? 'root');
        
        $categories = Cache::remember($cacheKey, 3600, function () use ($store, $request) {
            return $this->categoryService->getCategories(
                $store->magento_store_id,
                $request->parent_id,
                $request->boolean('include_products_count', false)
            );
        });

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                    'slug' => $store->store_slug,
                ],
                'categories' => $categories,
                'total' => count($categories),
            ]
        ]);
    }

    /**
     * Get single category by slug
     */
    public function show(Request $request, $slug)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|in:newest,price_asc,price_desc,popular',
        ]);

        $store = VendorStore::where('id', $request->store_id)
            ->where('status', 'active')
            ->firstOrFail();

        $cacheKey = "store_category_{$store->id}_{$slug}";
        
        $category = Cache::remember($cacheKey, 3600, function () use ($store, $slug) {
            return $this->categoryService->getCategoryBySlug(
                $store->magento_store_id,
                $slug
            );
        });

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }

        // Get products in this category
        $products = $this->categoryService->getCategoryProducts(
            $store->magento_store_id,
            $category['id'],
            $request->input('per_page', 20),
            $request->input('sort_by', 'newest')
        );

        // Get subcategories
        $subcategories = $this->categoryService->getSubcategories(
            $store->magento_store_id,
            $category['id']
        );

        // Get breadcrumb trail
        $breadcrumbs = $this->categoryService->getBreadcrumbs(
            $store->magento_store_id,
            $category['id']
        );

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                    'slug' => $store->store_slug,
                ],
                'category' => $category,
                'breadcrumbs' => $breadcrumbs,
                'subcategories' => $subcategories,
                'products' => $products,
                'filters' => $this->getAvailableFilters($store, $category['id']),
            ]
        ]);
    }

    /**
     * Get products by category slug
     */
    public function products(Request $request, $slug)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|in:newest,price_asc,price_desc,popular',
        ]);

        $store = VendorStore::where('id', $request->store_id)
            ->where('status', 'active')
            ->firstOrFail();

        $category = $this->categoryService->getCategoryBySlug(
            $store->magento_store_id,
            $slug
        );

        if (!$category) {
            return response()->json([
                'success' => false,
                'message' => 'Category not found'
            ], 404);
        }

        $products = $this->categoryService->getCategoryProducts(
            $store->magento_store_id,
            $category['id'],
            $request->input('per_page', 20),
            $request->input('sort_by', 'newest')
        );

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                    'slug' => $store->store_slug,
                ],
                'category' => $category,
                'products' => $products['items'],
                'total' => $products['total'],
            ]
        ]);
    }

    /**
     * Get category tree (hierarchical)
     */
    public function tree(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'max_depth' => 'nullable|integer|min:1|max:10',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        $depth = $request->max_depth ?? 5;
        $cacheKey = "store_category_tree_{$store->id}_depth_{$depth}";
        
        $tree = Cache::remember($cacheKey, 3600, function () use ($store, $request) {
            return $this->categoryService->getCategoryTree(
                $store->magento_store_id,
                $request->max_depth ?? 5
            );
        });

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                ],
                'tree' => $tree,
            ]
        ]);
    }

    /**
     * Get featured categories for homepage
     */
    public function featured(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        $cacheKey = "store_featured_categories_{$store->id}";
        
        $featured = Cache::remember($cacheKey, 7200, function () use ($store, $request) {
            return $this->categoryService->getFeaturedCategories(
                $store->magento_store_id,
                $request->limit ?? 8
            );
        });

        return response()->json([
            'success' => true,
            'data' => $featured
        ]);
    }

    /**
     * Get available filters for category
     */
    private function getAvailableFilters($store, $categoryId): array
    {
        // This would typically come from Magento layered navigation
        return [
            'price' => [
                'min' => 0,
                'max' => 1000,
                'step' => 10,
            ],
            'attributes' => [
                [
                    'name' => 'Size',
                    'code' => 'size',
                    'options' => ['S', 'M', 'L', 'XL'],
                ],
                [
                    'name' => 'Color',
                    'code' => 'color',
                    'options' => ['Red', 'Blue', 'Green', 'Black', 'White'],
                ],
                [
                    'name' => 'Brand',
                    'code' => 'brand',
                    'options' => ['Brand A', 'Brand B', 'Brand C'],
                ],
            ],
            'rating' => [1, 2, 3, 4, 5],
            'in_stock' => true,
        ];
    }

    /**
     * Clear category cache (admin only)
     */
    public function clearCache(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        // Clear all category caches for this store
        Cache::forget("store_categories_{$store->id}_root");
        Cache::forget("store_category_tree_{$store->id}_depth_5");
        Cache::forget("store_featured_categories_{$store->id}");
        
        // Clear pattern-based cache
        Cache::tags(["store_{$store->id}_categories"])->flush();

        return response()->json([
            'success' => true,
            'message' => 'Category cache cleared successfully'
        ]);
    }
}
