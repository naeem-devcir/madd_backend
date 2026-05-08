<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Models\Vendor\VendorStore;
use App\Services\Product\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class ProductCategoryController extends Controller
{
    public function __construct(protected CategoryService $categoryService) {}

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * storeSlug se active store lo + vendor eager load (credentials ke liye)
     */
    private function resolveStore(string $storeSlug): VendorStore
    {
        return VendorStore::with('vendor')      // credentials yahan hain
            ->where('store_slug', $storeSlug)
            ->where('status', 'active')
            ->firstOrFail();
    }

    private function storeInfo(VendorStore $store): array
    {
        return [
            'id'   => $store->id,
            'name' => $store->store_name,
            'slug' => $store->store_slug,
        ];
    }

    private function notFound(string $msg): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $msg], 404);
    }

    private function serverError(string $msg, Throwable $e): JsonResponse
    {
        report($e);
        return response()->json([
            'success' => false,
            'message' => $msg,
            'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
        ], 500);
    }

    // -------------------------------------------------------------------------
    // GET /catalog/{storeSlug}/categories
    // -------------------------------------------------------------------------

    public function index(Request $request, string $storeSlug): JsonResponse
    {
        $request->validate([
            'parent_id'             => 'nullable|integer',
            'include_products_count' => 'boolean',
        ]);

        try {
            $store    = $this->resolveStore($storeSlug);
            $parentId = $request->input('parent_id');  // null = root

            $cacheKey = "store_categories_{$store->id}_" . ($parentId ?? 'root');

            $categories = Cache::remember($cacheKey, 3600, function () use ($store, $request, $parentId) {
                // CategoryService ko vendor pass karo — us se MagentoService credentials lega
                return $this->categoryService->getCategories(
                    $store->vendor,                 // ← vendor, not store_id
                    $store->magento_store_id,
                    $parentId,
                    $request->boolean('include_products_count', false)
                );
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'store'      => $this->storeInfo($store),
                    'categories' => $categories,
                    'total'      => count($categories),
                ],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound('Store not found or inactive');
        } catch (Throwable $e) {
            return $this->serverError('Failed to fetch categories', $e);
        }
    }

    // -------------------------------------------------------------------------
    // GET /catalog/{storeSlug}/categories/tree
    // -------------------------------------------------------------------------

    public function tree(Request $request, string $storeSlug): JsonResponse
    {
        $request->validate([
            'max_depth' => 'nullable|integer|min:1|max:10',
        ]);

        try {
            $store = $this->resolveStore($storeSlug);
            $depth = (int) $request->input('max_depth', 5);

            $cacheKey = "store_category_tree_{$store->id}_depth_{$depth}";

            $tree = Cache::remember($cacheKey, 3600, function () use ($store, $depth) {
                return $this->categoryService->getCategoryTree(
                    $store->vendor,
                    $store->magento_store_id,
                    $depth
                );
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'store' => $this->storeInfo($store),
                    'tree'  => $tree,
                ],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound('Store not found or inactive');
        } catch (Throwable $e) {
            return $this->serverError('Failed to fetch category tree', $e);
        }
    }

    // -------------------------------------------------------------------------
    // GET /catalog/{storeSlug}/categories/featured
    // -------------------------------------------------------------------------

    public function featured(Request $request, string $storeSlug): JsonResponse
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        try {
            $store = $this->resolveStore($storeSlug);
            $limit = (int) $request->input('limit', 8);

            $cacheKey = "store_featured_categories_{$store->id}_limit_{$limit}";

            $featured = Cache::remember($cacheKey, 7200, function () use ($store, $limit) {
                return $this->categoryService->getFeaturedCategories(
                    $store->vendor,
                    $store->magento_store_id,
                    $limit
                );
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'store'      => $this->storeInfo($store),
                    'categories' => $featured,
                ],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound('Store not found or inactive');
        } catch (Throwable $e) {
            return $this->serverError('Failed to fetch featured categories', $e);
        }
    }

    // -------------------------------------------------------------------------
    // GET /catalog/{storeSlug}/categories/{slug}
    // -------------------------------------------------------------------------

    public function show(Request $request, string $storeSlug, string $slug): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by'  => 'nullable|in:newest,price_asc,price_desc,popular',
        ]);

        try {
            $store = $this->resolveStore($storeSlug);

            $cacheKey = "store_category_{$store->id}_{$slug}";

            $category = Cache::remember($cacheKey, 3600, function () use ($store, $slug) {
                return $this->categoryService->getCategoryBySlug(
                    $store->vendor,
                    $store->magento_store_id,
                    $slug
                );
            });

            if (! $category) {
                return $this->notFound('Category not found');
            }

            // Products, subcategories, breadcrumbs — not cached (paginated/sorted)
            $perPage = (int) $request->input('per_page', 20);
            $sortBy  = $request->input('sort_by', 'newest');

            [$products, $subcategories, $breadcrumbs] = [
                $this->categoryService->getCategoryProducts(
                    $store->vendor, $store->magento_store_id, $category['id'], $perPage, $sortBy
                ),
                $this->categoryService->getSubcategories(
                    $store->vendor, $store->magento_store_id, $category['id']
                ),
                $this->categoryService->getBreadcrumbs(
                    $store->vendor, $store->magento_store_id, $category['id']
                ),
            ];

            return response()->json([
                'success' => true,
                'data'    => [
                    'store'         => $this->storeInfo($store),
                    'category'      => $category,
                    'breadcrumbs'   => $breadcrumbs,
                    'subcategories' => $subcategories,
                    'products'      => $products,
                ],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound('Store not found or inactive');
        } catch (Throwable $e) {
            return $this->serverError('Failed to fetch category', $e);
        }
    }

    // -------------------------------------------------------------------------
    // GET /catalog/{storeSlug}/categories/{slug}/products
    // -------------------------------------------------------------------------

    public function products(Request $request, string $storeSlug, string $slug): JsonResponse
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by'  => 'nullable|in:newest,price_asc,price_desc,popular',
        ]);

        try {
            $store = $this->resolveStore($storeSlug);

            $category = $this->categoryService->getCategoryBySlug(
                $store->vendor,
                $store->magento_store_id,
                $slug
            );

            if (! $category) {
                return $this->notFound('Category not found');
            }

            $products = $this->categoryService->getCategoryProducts(
                $store->vendor,
                $store->magento_store_id,
                $category['id'],
                (int) $request->input('per_page', 20),
                $request->input('sort_by', 'newest')
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'store'    => $this->storeInfo($store),
                    'category' => $category,
                    'products' => $products['items'] ?? $products,
                    'total'    => $products['total'] ?? count($products),
                ],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound('Store not found or inactive');
        } catch (Throwable $e) {
            return $this->serverError('Failed to fetch category products', $e);
        }
    }
}