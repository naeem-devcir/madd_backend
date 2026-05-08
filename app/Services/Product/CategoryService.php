<?php

namespace App\Services\Product;

use App\Models\Vendor\Vendor;
use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\Cache;

class CategoryService
{
    private function magento(Vendor $vendor): MagentoService
    {
        return new MagentoService($vendor); // vendor ke credentials use honge
    }

    public function getCategories(Vendor $vendor, ?int $magentoStoreId, ?int $parentId = null, bool $includeCount = false): array
    {
        $magento = $this->magento($vendor);

        $params = ['rootCategoryId' => $parentId ?? 1];
        if ($magentoStoreId) {
            $params['storeId'] = $magentoStoreId;
        }

        $response = $magento->get('categories', $params);

        return $this->flattenCategories($response['children_data'] ?? []);
    }

    public function getCategoryTree(Vendor $vendor, ?int $magentoStoreId, int $depth = 5): array
    {
        $magento  = $this->magento($vendor);
        $params   = ['depth' => $depth];

        if ($magentoStoreId) {
            $params['storeId'] = $magentoStoreId;
        }

        return $magento->get('categories', $params);
    }

    public function getCategoryBySlug(Vendor $vendor, ?int $magentoStoreId, string $slug): ?array
    {
        // Magento mein slug = url_key custom attribute
        $magento  = $this->magento($vendor);

        $response = $magento->get('categories/list', [
            'searchCriteria[filterGroups][0][filters][0][field]'          => 'url_key',
            'searchCriteria[filterGroups][0][filters][0][value]'          => $slug,
            'searchCriteria[filterGroups][0][filters][0][conditionType]'  => 'eq',
            'searchCriteria[pageSize]'                                    => 1,
        ]);

        return $response['items'][0] ?? null;
    }

    public function getCategoryProducts(Vendor $vendor, ?int $magentoStoreId, int $categoryId, int $perPage = 20, string $sortBy = 'newest'): array
    {
        $magento = $this->magento($vendor);

        [$sortField, $sortDir] = match ($sortBy) {
            'price_asc'  => ['price', 'ASC'],
            'price_desc' => ['price', 'DESC'],
            'popular'    => ['sold_qty', 'DESC'],
            default      => ['created_at', 'DESC'],  // newest
        };

        $response = $magento->get('products', [
            'searchCriteria[filterGroups][0][filters][0][field]'         => 'category_id',
            'searchCriteria[filterGroups][0][filters][0][value]'         => $categoryId,
            'searchCriteria[filterGroups][0][filters][0][conditionType]' => 'eq',
            'searchCriteria[sortOrders][0][field]'                       => $sortField,
            'searchCriteria[sortOrders][0][direction]'                   => $sortDir,
            'searchCriteria[pageSize]'                                   => $perPage,
            'searchCriteria[currentPage]'                                => 1,
        ]);

        return [
            'items' => $response['items'] ?? [],
            'total' => $response['total_count'] ?? 0,
        ];
    }

    public function getSubcategories(Vendor $vendor, ?int $magentoStoreId, int $parentId): array
    {
        $magento  = $this->magento($vendor);

        $response = $magento->get('categories/list', [
            'searchCriteria[filterGroups][0][filters][0][field]'         => 'parent_id',
            'searchCriteria[filterGroups][0][filters][0][value]'         => $parentId,
            'searchCriteria[filterGroups][0][filters][0][conditionType]' => 'eq',
            'searchCriteria[filterGroups][1][filters][0][field]'         => 'is_active',
            'searchCriteria[filterGroups][1][filters][0][value]'         => 1,
        ]);

        return $response['items'] ?? [];
    }

    public function getBreadcrumbs(Vendor $vendor, ?int $magentoStoreId, int $categoryId): array
    {
        // Magento mein direct breadcrumb API nahi — path se build karte hain
        $magento  = $this->magento($vendor);
        $category = $magento->get("categories/{$categoryId}");

        $breadcrumbs = [];
        $pathIds     = array_filter(explode('/', $category['path'] ?? ''));

        // Skip root (1) and Default Category (2)
        $relevantIds = array_slice(array_values($pathIds), 2);

        foreach ($relevantIds as $id) {
            try {
                $cat = $magento->get("categories/{$id}");
                $breadcrumbs[] = [
                    'id'      => $cat['id'],
                    'name'    => $cat['name'],
                    'url_key' => data_get($cat, 'custom_attributes.*.value', $cat['id']),
                ];
            } catch (\Throwable) {
                // skip missing categories
            }
        }

        return $breadcrumbs;
    }

    public function getFeaturedCategories(Vendor $vendor, ?int $magentoStoreId, int $limit = 8): array
    {
        $magento  = $this->magento($vendor);

        // is_anchor=1 categories are typically featured/navigation categories
        $response = $magento->get('categories/list', [
            'searchCriteria[filterGroups][0][filters][0][field]'         => 'is_active',
            'searchCriteria[filterGroups][0][filters][0][value]'         => 1,
            'searchCriteria[filterGroups][1][filters][0][field]'         => 'include_in_menu',
            'searchCriteria[filterGroups][1][filters][0][value]'         => 1,
            'searchCriteria[filterGroups][1][filters][0][conditionType]' => 'eq',
            'searchCriteria[pageSize]'                                   => $limit,
        ]);

        return $response['items'] ?? [];
    }

    private function flattenCategories(array $children): array
    {
        $result = [];
        foreach ($children as $cat) {
            $result[] = [
                'id'       => $cat['id'],
                'name'     => $cat['name'],
                'level'    => $cat['level'] ?? null,
                'url_key'  => $cat['url_key'] ?? null,
                'children' => $this->flattenCategories($cat['children_data'] ?? []),
            ];
        }
        return $result;
    }
}