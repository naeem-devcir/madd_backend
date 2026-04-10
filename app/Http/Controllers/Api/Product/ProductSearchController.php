<?php

namespace App\Http\Controllers\Api\Product;

use App\Http\Controllers\Controller;
use App\Models\Vendor\VendorStore;
use App\Services\Product\SearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; 


class ProductSearchController extends Controller
{
    protected $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * Search products across store
     */
    public function search(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'query' => 'required|string|min:2|max:100',
            'category' => 'nullable|string',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'brand' => 'nullable|string',
            'rating' => 'nullable|integer|min:1|max:5',
            'in_stock' => 'boolean',
            'sort_by' => 'nullable|in:relevance,price_asc,price_desc,newest,rating',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $store = VendorStore::findOrFail($request->store_id);



            Log::info($store . "ssssssssssssssssssssssss" );

        // Build search parameters
        $params = [
            'query' => $request->input('query'),
            'store_id' => $store->magento_store_id,
            'store_id' => $store->magento_store_id,
            'category' => $request->category,
            'min_price' => $request->min_price,
            'max_price' => $request->max_price,
            'brand' => $request->brand,
            'rating' => $request->rating,
            'in_stock' => $request->boolean('in_stock'),
            'sort_by' => $request->sort_by ?? 'relevance',
            'page' => $request->page ?? 1,
            'per_page' => $request->per_page ?? 20,
        ];

        // Cache search results for popular queries (optional)
        $cacheKey = $this->generateCacheKey($params);
        $shouldCache = $this->shouldCacheSearch($request->input('query'));
        
        if ($shouldCache && Cache::has($cacheKey)) {
            $results = Cache::get($cacheKey);
        } else {
            $results = $this->searchService->search($params);
            
            if ($shouldCache) {
                Cache::put($cacheKey, $results, 300); // Cache for 5 minutes
            }
        }

        // Log search for analytics (async)
        $this->logSearch($request, $store, $results['total']);

        return response()->json([
            'success' => true,
            'data' => [
                'query' => $request->input('query'),
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                ],
                'results' => $results['items'],
                'total' => $results['total'],
                'current_page' => $results['current_page'],
                'last_page' => $results['last_page'],
                'per_page' => $results['per_page'],
                'suggestions' => $results['suggestions'] ?? [],
                'filters' => $results['filters'] ?? [],
                'did_you_mean' => $results['did_you_mean'] ?? null,
            ]
        ]);
    }

    /**
     * Autocomplete search suggestions
     */
    public function suggest(Request $request)
    {
        return $this->autocomplete($request);
    }

    /**
     * Autocomplete search suggestions
     */
    public function autocomplete(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'query' => 'required|string|min:1|max:50',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        $cacheKey = "search_autocomplete_{$store->id}_{$request->input('query')}";
        
        $suggestions = Cache::remember($cacheKey, 300, function () use ($store, $request) {
            return $this->searchService->autocomplete(
                $store->magento_store_id,
                $request->input('query'),
                $request->limit ?? 10
            );
        });

        return response()->json([
            'success' => true,
            'data' => [
                'query' => $request->input('query'),
                'suggestions' => $suggestions,
                'popular_searches' => $this->getPopularSearches($store->id),
            ]
        ]);
    }

    /**
     * Advanced search with multiple filters
     */
    public function advanced(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'filters' => 'required|array',
            'filters.category' => 'nullable|string',
            'filters.price_range' => 'nullable|array',
            'filters.price_range.min' => 'nullable|numeric|min:0',
            'filters.price_range.max' => 'nullable|numeric|min:0',
            'filters.attributes' => 'nullable|array',
            'filters.rating' => 'nullable|integer|min:1|max:5',
            'filters.in_stock' => 'boolean',
            'filters.on_sale' => 'boolean',
            'sort_by' => 'nullable|string',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        $results = $this->searchService->advancedSearch([
            'store_id' => $store->magento_store_id,
            'filters' => $request->filters,
            'sort_by' => $request->sort_by ?? 'relevance',
            'page' => $request->page ?? 1,
            'per_page' => $request->per_page ?? 20,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'filters' => $request->filters,
                'results' => $results['items'],
                'total' => $results['total'],
                'current_page' => $results['current_page'],
                'last_page' => $results['last_page'],
                'available_filters' => $results['available_filters'] ?? [],
            ]
        ]);
    }

    /**
     * Get popular searches for a store
     */
    public function popular(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        $popularSearches = $this->getPopularSearches($store->id, $request->limit ?? 10);

        return response()->json([
            'success' => true,
            'data' => $popularSearches
        ]);
    }

    /**
     * Get search filters configuration
     */
    public function filters(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'category' => 'nullable|string',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        $cacheKey = "search_filters_{$store->id}_" . ($request->category ?? 'all');
        
        $filters = Cache::remember($cacheKey, 3600, function () use ($store, $request) {
            return $this->searchService->getAvailableFilters(
                $store->magento_store_id,
                $request->category
            );
        });

        return response()->json([
            'success' => true,
            'data' => $filters
        ]);
    }

    /**
     * Get search statistics (admin only)
     */
    public function statistics(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'period' => 'nullable|in:today,week,month',
        ]);

        $store = VendorStore::findOrFail($request->store_id);

        $period = $request->period ?? 'week';
        $startDate = match($period) {
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfWeek(),
        };

        $stats = [
            'total_searches' => \App\Models\Analytics\SearchLog::where('store_id', $store->id)
                ->where('created_at', '>=', $startDate)
                ->count(),
            'unique_searches' => \App\Models\Analytics\SearchLog::where('store_id', $store->id)
                ->where('created_at', '>=', $startDate)
                ->distinct('query')
                ->count('query'),
            'zero_results' => \App\Models\Analytics\SearchLog::where('store_id', $store->id)
                ->where('created_at', '>=', $startDate)
                ->where('results_count', 0)
                ->count(),
            'avg_results_per_search' => \App\Models\Analytics\SearchLog::where('store_id', $store->id)
                ->where('created_at', '>=', $startDate)
                ->avg('results_count'),
            'top_searches' => \App\Models\Analytics\SearchLog::where('store_id', $store->id)
                ->where('created_at', '>=', $startDate)
                ->select('query', DB::raw('COUNT(*) as count'))
                ->groupBy('query')
                ->orderBy('count', 'desc')
                ->limit(20)
                ->get(),
            'searches_with_clicks' => \App\Models\Analytics\SearchLog::where('store_id', $store->id)
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('clicked_product')
                ->count(),
            'conversion_rate' => $this->calculateConversionRate($store->id, $startDate),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'statistics' => $stats,
            ]
        ]);
    }

    /**
     * Generate cache key for search query
     */
    private function generateCacheKey(array $params): string
    {
        ksort($params);
        return 'search_' . md5(json_encode($params));
    }

    /**
     * Determine if search should be cached
     */
    private function shouldCacheSearch(string $query): bool
    {
        // Cache only longer queries that are more likely to be repeated
        return strlen($query) > 3;
    }

    /**
     * Log search for analytics
     */
    private function logSearch(Request $request, VendorStore $store, int $resultCount): void
    {
        try {
            \App\Models\Analytics\SearchLog::create([
                'store_id' => $store->id,
                'user_id' => auth()->id(),
                'session_id' => $request->session()->getId(),
                'query' => $request->input('query'),
                'results_count' => $resultCount,
                'filters_applied' => $request->except(['store_id', 'query', 'page', 'per_page']),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (\Exception $e) {
            // Log error but don't break the search response
            \Log::error('Failed to log search', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get popular searches for a store
     */
    private function getPopularSearches($storeId, $limit = 10): array
    {
        $cacheKey = "popular_searches_{$storeId}";
        
        return Cache::remember($cacheKey, 3600, function () use ($storeId, $limit) {
            return \App\Models\Analytics\SearchLog::where('store_id', $storeId)
                ->where('created_at', '>=', now()->subDays(30))
                ->select('query', DB::raw('COUNT(*) as count'))
                ->groupBy('query')
                ->orderBy('count', 'desc')
                ->limit($limit)
                ->get()
                ->pluck('query')
                ->toArray();
        });
    }

    /**
     * Calculate search to purchase conversion rate
     */
    private function calculateConversionRate($storeId, $startDate): float
    {
        $searchesWithClicks = \App\Models\Analytics\SearchLog::where('store_id', $storeId)
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('clicked_product')
            ->count();

        $totalSearches = \App\Models\Analytics\SearchLog::where('store_id', $storeId)
            ->where('created_at', '>=', $startDate)
            ->count();

        if ($totalSearches === 0) {
            return 0;
        }

        // Get purchases from clicked products within 24 hours of search
        $purchases = \App\Models\Analytics\SearchLog::where('store_id', $storeId)
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('clicked_product')
            ->whereHas('orderItem', function($query) {
                $query->where('created_at', '<=', DB::raw('search_logs.created_at + INTERVAL 1 DAY'));
            })
            ->count();

        return round(($purchases / $totalSearches) * 100, 2);
    }
}
