<?php

namespace App\Services\Product;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CategoryService
{
    protected $magentoBaseUrl;
    protected $magentoToken;

    public function __construct()
    {
        $this->magentoBaseUrl = config('services.magento.base_url');
        $this->magentoToken = config('services.magento.admin_token');
    }

    /**
     * Get categories for a store
     */
    public function getCategories($storeId, $parentId = null, $includeCounts = false): array
    {
        try {
            $response = Http::withToken($this->magentoToken)
                ->get($this->magentoBaseUrl . '/rest/V1/categories', [
                    'searchCriteria' => [
                        'filter_groups' => [
                            [
                                'filters' => [
                                    [
                                        'field' => 'parent_id',
                                        'value' => $parentId ?? 0,
                                        'condition_type' => 'eq',
                                    ],
                                    [
                                        'field' => 'is_active',
                                        'value' => 1,
                                        'condition_type' => 'eq',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $categories = $response->json()['items'] ?? [];
                
                if ($includeCounts) {
                    foreach ($categories as &$category) {
                        $category['product_count'] = $this->getCategoryProductCount($category['id']);
                    }
                }
                
                return $this->formatCategories($categories);
            }

            return $this->getFallbackCategories();

        } catch (\Exception $e) {
            Log::error('Category service error', ['error' => $e->getMessage()]);
            return $this->getFallbackCategories();
        }
    }

    /**
     * Get category by slug
     */
    public function getCategoryBySlug($storeId, $slug): ?array
    {
        try {
            $response = Http::withToken($this->magentoToken)
                ->get($this->magentoBaseUrl . '/rest/V1/categories', [
                    'searchCriteria' => [
                        'filter_groups' => [
                            [
                                'filters' => [
                                    [
                                        'field' => 'url_key',
                                        'value' => $slug,
                                        'condition_type' => 'eq',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $categories = $response->json()['items'] ?? [];
                if (!empty($categories)) {
                    return $this->formatCategory($categories[0]);
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Get category by slug error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get category tree
     */
    public function getCategoryTree($storeId, $maxDepth = 5): array
    {
        try {
            $response = Http::withToken($this->magentoToken)
                ->get($this->magentoBaseUrl . '/rest/V1/categories');

            if ($response->successful()) {
                $rootCategory = $response->json();
                return $this->buildCategoryTree($rootCategory, $maxDepth);
            }

            return [];

        } catch (\Exception $e) {
            Log::error('Get category tree error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get category products
     */
    public function getCategoryProducts($storeId, $categoryId, $perPage = 20, $sortBy = 'newest')
    {
        try {
            $sortMap = [
                'newest' => ['field' => 'created_at', 'direction' => 'DESC'],
                'price_asc' => ['field' => 'price', 'direction' => 'ASC'],
                'price_desc' => ['field' => 'price', 'direction' => 'DESC'],
                'popular' => ['field' => 'sales', 'direction' => 'DESC'],
            ];

            $response = Http::withToken($this->magentoToken)
                ->get($this->magentoBaseUrl . '/rest/V1/products', [
                    'searchCriteria' => [
                        'filter_groups' => [
                            [
                                'filters' => [
                                    [
                                        'field' => 'category_id',
                                        'value' => $categoryId,
                                        'condition_type' => 'eq',
                                    ],
                                ],
                            ],
                        ],
                        'sort_orders' => [$sortMap[$sortBy] ?? $sortMap['newest']],
                        'page_size' => $perPage,
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'items' => $data['items'] ?? [],
                    'total' => $data['total_count'] ?? 0,
                ];
            }

            return ['items' => [], 'total' => 0];

        } catch (\Exception $e) {
            Log::error('Get category products error', ['error' => $e->getMessage()]);
            return ['items' => [], 'total' => 0];
        }
    }

    /**
     * Get subcategories
     */
    public function getSubcategories($storeId, $categoryId): array
    {
        return $this->getCategories($storeId, $categoryId);
    }

    /**
     * Get breadcrumbs for category
     */
    public function getBreadcrumbs($storeId, $categoryId): array
    {
        $breadcrumbs = [];
        $category = $this->getCategoryById($categoryId);
        
        while ($category && $category['parent_id'] != 0) {
            array_unshift($breadcrumbs, [
                'id' => $category['id'],
                'name' => $category['name'],
                'slug' => $category['url_key'],
            ]);
            $category = $this->getCategoryById($category['parent_id']);
        }
        
        return $breadcrumbs;
    }

    /**
     * Get featured categories
     */
    public function getFeaturedCategories($storeId, $limit = 8): array
    {
        $categories = $this->getCategories($storeId, null, true);
        
        // Sort by product count and take top ones
        usort($categories, function($a, $b) {
            return $b['product_count'] <=> $a['product_count'];
        });
        
        return array_slice($categories, 0, $limit);
    }

    /**
     * Get category by ID
     */
    private function getCategoryById($categoryId): ?array
    {
        try {
            $response = Http::withToken($this->magentoToken)
                ->get($this->magentoBaseUrl . "/rest/V1/categories/{$categoryId}");

            if ($response->successful()) {
                return $this->formatCategory($response->json());
            }

            return null;

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get product count for category
     */
    private function getCategoryProductCount($categoryId): int
    {
        try {
            $response = Http::withToken($this->magentoToken)
                ->get($this->magentoBaseUrl . '/rest/V1/products', [
                    'searchCriteria' => [
                        'filter_groups' => [
                            [
                                'filters' => [
                                    [
                                        'field' => 'category_id',
                                        'value' => $categoryId,
                                        'condition_type' => 'eq',
                                    ],
                                ],
                            ],
                        ],
                        'page_size' => 1,
                    ],
                ]);

            if ($response->successful()) {
                return $response->json()['total_count'] ?? 0;
            }

            return 0;

        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Format category data
     */
    private function formatCategory($category): array
    {
        return [
            'id' => $category['id'],
            'name' => $category['name'],
            'slug' => $category['url_key'] ?? $this->slugify($category['name']),
            'description' => $category['description'] ?? null,
            'image' => $category['image'] ?? null,
            'parent_id' => $category['parent_id'],
            'level' => $category['level'],
            'position' => $category['position'],
            'is_active' => $category['is_active'],
            'product_count' => $category['product_count'] ?? 0,
            'children' => [],
        ];
    }

    /**
     * Format multiple categories
     */
    private function formatCategories(array $categories): array
    {
        return array_map([$this, 'formatCategory'], $categories);
    }

    /**
     * Build category tree recursively
     */
    private function buildCategoryTree($category, $maxDepth, $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $formatted = $this->formatCategory($category);
        
        if (!empty($category['children_data'])) {
            foreach ($category['children_data'] as $child) {
                $formatted['children'][] = $this->buildCategoryTree($child, $maxDepth, $currentDepth + 1);
            }
        }
        
        return $formatted;
    }

    /**
     * Create slug from string
     */
    private function slugify($string): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
    }

    /**
     * Get fallback categories
     */
    private function getFallbackCategories(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Electronics',
                'slug' => 'electronics',
                'product_count' => 0,
                'children' => [],
            ],
            [
                'id' => 2,
                'name' => 'Clothing',
                'slug' => 'clothing',
                'product_count' => 0,
                'children' => [],
            ],
            [
                'id' => 3,
                'name' => 'Home & Garden',
                'slug' => 'home-garden',
                'product_count' => 0,
                'children' => [],
            ],
        ];
    }
}