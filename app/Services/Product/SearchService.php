<?php

namespace App\Services\Product;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SearchService
{
    protected $magentoBaseUrl;
    protected $magentoToken;

    public function __construct()
    {
        $this->magentoBaseUrl = config('services.magento.base_url');
        $this->magentoToken = config('services.magento.admin_token');
    }

    /**
     * Perform product search
     */
    public function search(array $params): array
    {
        try {
            // For production, this should call Magento GraphQL or REST API
            // This is a simplified implementation
            
            $response = Http::withToken($this->magentoToken)
                ->get($this->magentoBaseUrl . '/rest/V1/products', [
                    'searchCriteria' => [
                        'filter_groups' => $this->buildFilters($params),
                        'current_page' => $params['page'],
                        'page_size' => $params['per_page'],
                        'sort_orders' => $this->buildSortOrders($params['sort_by']),
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'items' => $data['items'] ?? [],
                    'total' => $data['total_count'] ?? 0,
                    'current_page' => $params['page'],
                    'per_page' => $params['per_page'],
                    'last_page' => ceil(($data['total_count'] ?? 0) / $params['per_page']),
                    'suggestions' => $this->getSuggestions($params['query']),
                    'filters' => $this->getAvailableFilters($params),
                ];
            }

            return $this->getFallbackResults($params);

        } catch (\Exception $e) {
            Log::error('Search service error', ['error' => $e->getMessage()]);
            return $this->getFallbackResults($params);
        }
    }

    /**
     * Get autocomplete suggestions
     */
    public function autocomplete($storeId, string $query, int $limit): array
    {
        // This would call Magento search suggestion API
        // Simplified implementation
        return [
            ['text' => $query . ' product 1', 'type' => 'product'],
            ['text' => $query . ' product 2', 'type' => 'product'],
            ['text' => $query . ' category', 'type' => 'category'],
        ];
    }

    /**
     * Advanced search with multiple filters
     */
    public function advancedSearch(array $params): array
    {
        return $this->search([
            'store_id' => $params['store_id'],
            'query' => '',
            'page' => $params['page'],
            'per_page' => $params['per_page'],
            'sort_by' => $params['sort_by'],
            ...$params['filters']
        ]);
    }

    /**
     * Get available filters for search
     */
    public function getAvailableFilters($storeId, $category = null): array
    {
        // This would come from Magento layered navigation
        return [
            'price' => [
                'type' => 'range',
                'min' => 0,
                'max' => 1000,
                'step' => 10,
            ],
            'categories' => [
                'type' => 'multiselect',
                'options' => [
                    ['id' => 1, 'name' => 'Electronics', 'count' => 45],
                    ['id' => 2, 'name' => 'Clothing', 'count' => 32],
                    ['id' => 3, 'name' => 'Home & Garden', 'count' => 28],
                ],
            ],
            'attributes' => [
                [
                    'name' => 'Size',
                    'code' => 'size',
                    'type' => 'select',
                    'options' => ['S', 'M', 'L', 'XL'],
                ],
                [
                    'name' => 'Color',
                    'code' => 'color',
                    'type' => 'swatch',
                    'options' => ['Red', 'Blue', 'Green', 'Black'],
                ],
            ],
            'rating' => [
                'type' => 'rating',
                'options' => [1, 2, 3, 4, 5],
            ],
            'in_stock' => [
                'type' => 'boolean',
            ],
        ];
    }

    /**
     * Build filters for Magento API
     */
    private function buildFilters(array $params): array
    {
        $filters = [];

        if (!empty($params['query'])) {
            $filters[] = [
                'name' => 'search',
                'value' => $params['query'],
            ];
        }

        if (!empty($params['category'])) {
            $filters[] = [
                'name' => 'category_id',
                'value' => $params['category'],
                'condition_type' => 'eq',
            ];
        }

        if (isset($params['min_price']) || isset($params['max_price'])) {
            $filters[] = [
                'name' => 'price',
                'value' => ($params['min_price'] ?? 0) . '_' . ($params['max_price'] ?? 999999),
                'condition_type' => 'from_to',
            ];
        }

        return [$filters];
    }

    /**
     * Build sort orders for Magento API
     */
    private function buildSortOrders(string $sortBy): array
    {
        $sortMap = [
            'relevance' => ['field' => 'relevance', 'direction' => 'DESC'],
            'price_asc' => ['field' => 'price', 'direction' => 'ASC'],
            'price_desc' => ['field' => 'price', 'direction' => 'DESC'],
            'newest' => ['field' => 'created_at', 'direction' => 'DESC'],
            'rating' => ['field' => 'rating', 'direction' => 'DESC'],
        ];

        return [$sortMap[$sortBy] ?? $sortMap['relevance']];
    }

    /**
     * Get search suggestions
     */
    private function getSuggestions(string $query): array
    {
        // This would call Magento search suggest API
        return [
            $query . ' premium',
            $query . ' deluxe',
            'best ' . $query,
        ];
    }

    /**
     * Get fallback results when search fails
     */
    private function getFallbackResults(array $params): array
    {
        return [
            'items' => [],
            'total' => 0,
            'current_page' => $params['page'],
            'per_page' => $params['per_page'],
            'last_page' => 1,
            'suggestions' => [],
            'filters' => [],
        ];
    }
}