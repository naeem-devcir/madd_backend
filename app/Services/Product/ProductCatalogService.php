<?php

namespace App\Services\Product;

use App\Models\Product\VendorProduct;
use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class ProductCatalogService
{
    protected $magentoBaseUrl;
    protected $magentoToken;

    public function __construct()
    {
        $this->magentoBaseUrl = config('services.magento.base_url');
        $this->magentoToken = config('services.magento.admin_token');
    }

    /**
     * Find active store by id.
     */
    public function findActiveStoreById(int|string $storeId): VendorStore
    {
        return VendorStore::where('id', $storeId)
            ->where('status', 'active')
            ->firstOrFail();
    }

    /**
     * Find active store by slug.
     */
    public function findActiveStoreBySlug(string $storeSlug): VendorStore
    {
        return VendorStore::where('store_slug', $storeSlug)
            ->where('status', 'active')
            ->firstOrFail();
    }

    /**
     * Get paginated products for a store.
     */
    public function paginateStoreProducts(VendorStore $store, array $filters = []): LengthAwarePaginator
    {
        try {
            $query = $this->buildStoreProductsQuery($store, $filters);
            $this->applySorting($query, $filters['sort_by'] ?? 'newest');

            return $query->paginate((int) ($filters['per_page'] ?? 20));
        } catch (\Exception $e) {
            Log::error('Product catalog pagination error', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return VendorProduct::whereRaw('1 = 0')->paginate((int) ($filters['per_page'] ?? 20));
        }
    }

    /**
     * Build catalog listing response data for controller index action.
     */
    public function getIndexData(array $filters): array
    {
        $store = $this->findActiveStoreById($filters['store_id']);
        $products = $this->paginateStoreProducts($store, $filters);

        return [
            'store' => $this->formatStoreSummary($store),
            'products' => $products,
            'filters' => [
                'categories' => $this->getStoreCategories($store),
                'price_range' => $this->getStorePriceRange($store),
            ],
        ];
    }

    /**
     * Backward-compatible alias for catalog listing data.
     */
    public function getProducts(array $filters): array
    {
        return $this->getIndexData($filters);
    }

    /**
     * Find a product inside a store by public identifier.
     */
    public function findStoreProduct(VendorStore $store, string $productSlug): VendorProduct
    {
        try {
            return VendorProduct::where('vendor_store_id', $store->uuid)
                ->where(function ($query) use ($productSlug) {
                    $query->where('sku', $productSlug)
                        ->orWhere('magento_sku', $productSlug);
                })
                ->firstOrFail();
        } catch (\Exception $e) {
            Log::error('Find store product error', [
                'store_id' => $store->id,
                'product_slug' => $productSlug,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build product detail response data for controller show action.
     */
    public function getShowData(string $storeSlug, string $productSlug): array
    {
        $store = $this->findActiveStoreBySlug($storeSlug);
        $product = $this->findStoreProduct($store, $productSlug);

        return [
            'store' => [
                'id' => $store->id,
                'name' => $store->store_name,
            ],
            'product' => $product,
            'reviews' => $product->reviews()
                ->where('status', 'approved')
                ->with('customer')
                ->paginate(10),
        ];
    }

    /**
     * Backward-compatible alias for product detail data.
     */
    public function getProduct(string $storeSlug, string $productSlug): array
    {
        return $this->getShowData($storeSlug, $productSlug);
    }

    /**
     * Find product by SKU.
     */
    public function findActiveProductBySku(string $sku): VendorProduct
    {
        try {
            return VendorProduct::where('sku', $sku)
                ->where('status', 'active')
                ->with(['vendor', 'store'])
                ->firstOrFail();
        } catch (\Exception $e) {
            Log::error('Find product by SKU error', [
                'sku' => $sku,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build product-by-sku response data for controller showBySku action.
     */
    public function getShowBySkuData(string $sku): VendorProduct
    {
        return $this->findActiveProductBySku($sku);
    }

    /**
     * Backward-compatible alias for product-by-sku data.
     */
    public function getProductBySku(string $sku): array
    {
        return [
            'product' => $this->getShowBySkuData($sku),
        ];
    }

    /**
     * Get related products for product detail page.
     */
    public function getRelatedProducts(VendorProduct $product, int $limit = 10): Collection
    {
        try {
            return VendorProduct::where('vendor_id', $product->vendor_id)
                ->where('id', '!=', $product->id)
                ->where('status', 'active')
                ->limit($limit)
                ->get();
        } catch (\Exception $e) {
            Log::error('Get related products error', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return new Collection();
        }
    }

    /**
     * Build related products response data for controller related action.
     */
    public function getRelatedData(string $productId, int $limit = 10): Collection
    {
        $product = VendorProduct::find($productId);

        if (!$product) {
            throw (new ModelNotFoundException())->setModel(VendorProduct::class, [$productId]);
        }

        return $this->getRelatedProducts($product, $limit);
    }

    /**
     * Backward-compatible alias for related products data.
     */
    public function getRelatedProductsById(string $productId, int $limit = 10): Collection
    {
        return $this->getRelatedData($productId, $limit);
    }

    /**
     * Get store categories for catalog filters.
     */
    public function getStoreCategories(VendorStore $store): array
    {
        return [
            ['id' => 1, 'name' => 'Electronics', 'slug' => 'electronics'],
            ['id' => 2, 'name' => 'Clothing', 'slug' => 'clothing'],
            ['id' => 3, 'name' => 'Home & Garden', 'slug' => 'home-garden'],
        ];
    }

    /**
     * Get price range for a store catalog.
     */
    public function getStorePriceRange(VendorStore $store): array
    {
        try {
            $range = VendorProduct::where('vendor_store_id', $store->uuid)
                ->where('status', 'active')
                ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
                ->first();

            return [
                'min' => (float) ($range?->min_price ?? 0),
                'max' => (float) ($range?->max_price ?? 0),
            ];
        } catch (\Exception $e) {
            Log::error('Get store price range error', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'min' => 0,
                'max' => 0,
            ];
        }
    }

    /**
     * Create the base product query used by the controller's index action.
     */
    public function buildStoreProductsQuery(VendorStore $store, array $filters = []): Builder
    {
        $query = VendorProduct::query()
            ->where('vendor_store_id', $store->uuid)
            ->where('status', 'active')
            ->where('sync_status', 'synced');

        if (!empty($filters['category'])) {
            // Category mapping is not finalized yet in local schema.
            // Keep this hook here so controller/service contracts stay stable.
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== null) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== null) {
            $query->where('price', '<=', $filters['max_price']);
        }

        return $query;
    }

    private function applySorting($query, string $sortBy): void
    {
        switch ($sortBy) {
            case 'price_asc':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            case 'popular':
                $query->orderBy('created_at', 'desc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }
    }

    private function formatStoreSummary(VendorStore $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->store_name,
            'slug' => $store->store_slug,
        ];
    }
}
