<?php

namespace App\Services\Product;

use App\Models\Category;
use App\Models\Vendor\Vendor;
use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CategoryService
{
    protected ?Vendor $vendor = null;
    protected ?MagentoService $magentoService = null;

    /**
     * Get Magento service instance for a vendor
     */
    private function magentoForVendor(Vendor $vendor): MagentoService
    {
        return MagentoService::forVendor($vendor);
    }

    /**
     * Set vendor for current operation
     */
    public function forVendor(Vendor $vendor): self
    {
        $this->vendor = $vendor;
        $this->magentoService = $this->magentoForVendor($vendor);
        return $this;
    }

    /**
     * Get Magento service instance
     */
    protected function magento(): MagentoService
    {
        if (!$this->magentoService) {
            throw new \RuntimeException('Vendor not set. Call forVendor() first.');
        }
        return $this->magentoService;
    }

    // -------------------------------------------------------------------------
    // READ Operations (Local DB)
    // -------------------------------------------------------------------------

    /**
     * Get categories from local DB
     */
    public function getCategories(?string $parentUuid = null, bool $includeCount = false): array
    {
        $query = Category::forVendor($this->vendor->id)
            ->with('children')
            ->active();

        if ($parentUuid !== null) {
            if ($parentUuid === 'null' || $parentUuid === null) {
                $query->whereNull('parent_id');
            } else {
                $parentCategory = Category::forVendor($this->vendor->id)
                    ->where('uuid', $parentUuid)
                    ->first();

                if ($parentCategory) {
                    $query->where('parent_id', $parentCategory->id);
                }
            }
        } else {
            $query->rootLevel();
        }

        $categories = $query->orderBy('position')->get();

        $result = [];
        foreach ($categories as $category) {
            $data = [
                'uuid' => $category->uuid,
                'internal_id' => $category->id,
                'magento_id' => $category->magento_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'level' => $category->level,
                'is_active' => $category->is_active,
                'image_url' => $category->image_url,
                'position' => $category->position,
                'include_in_menu' => $category->include_in_menu,
            ];

            if ($includeCount) {
                $data['products_count'] = $category->products()->count() ?? 0;
            }

            if ($category->children->isNotEmpty()) {
                $data['children'] = $this->formatChildren($category->children);
            }

            $result[] = $data;
        }

        return $result;
    }

    /**
     * Get category tree from local DB
     */
    public function getCategoryTree(int $depth = 5): array
    {
        $categories = Category::forVendor($this->vendor->id)
            ->with(['children' => function ($query) use ($depth) {
                if ($depth > 1) {
                    $query->with(['children' => function ($q) use ($depth) {
                        if ($depth > 2) {
                            $q->with('children');
                        }
                    }]);
                }
            }])
            ->rootLevel()
            ->active()
            ->orderBy('position')
            ->get();

        return $this->buildTree($categories);
    }

    /**
     * Get category by UUID (from local DB)
     */
    public function getCategoryByUuid(string $uuid): ?array
    {
        $category = Category::forVendor($this->vendor->id)
            ->with('parent')
            ->where('uuid', $uuid)
            ->first();

        if (!$category) {
            return null;
        }

        return [
            'uuid' => $category->uuid,
            'internal_id' => $category->id,
            'magento_id' => $category->magento_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'level' => $category->level,
            'is_active' => $category->is_active,
            'meta_title' => $category->meta_title,
            'meta_description' => $category->meta_description,
            'position' => $category->position,
            'include_in_menu' => $category->include_in_menu,
            'image_url' => $category->image_url,
            'parent' => $category->parent ? [
                'uuid' => $category->parent->uuid,
                'name' => $category->parent->name,
                'slug' => $category->parent->slug,
            ] : null,
        ];
    }

    /**
     * Get products by category from Magento
     */
    public function getCategoryProducts(string $categoryUuid): array
    {
        $category = Category::forVendor($this->vendor->id)
            ->select(['uuid', 'name', 'magento_id'])
            ->where('uuid', $categoryUuid)
            ->firstOrFail();

        // Use the MagentoService to fetch products
        $response = $this->magento()->get("categories/{$category->magento_id}/products");

        return [
            'success' => true,
            'data' => $response,
            'category' => [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'magento_id' => $category->magento_id
            ]
        ];
    }

    /**
     * Get single category from Magento by ID
     */
    public function getMagentoCategory(int $magentoId): array
    {
        return $this->magento()->get("categories/{$magentoId}");
    }

    // -------------------------------------------------------------------------
    // WRITE Operations (Magento First, Then Local)
    // -------------------------------------------------------------------------

    /**
     * Create category (WRITE to Magento first, then local)
     */
    public function createCategory(array $data): array
    {
        DB::beginTransaction();

        try {
            // 1. Create in Magento with complete payload
            $magentoCategory = $this->createCategoryInMagento($data);

            // 2. Sync to local DB
            $localCategory = $this->syncFromMagento($magentoCategory);

            DB::commit();

            return [
                'success' => true,
                'data' => [
                    'uuid' => $localCategory->uuid,
                    'internal_id' => $localCategory->id,
                    'magento_id' => $localCategory->magento_id,
                    'name' => $localCategory->name,
                ],
                'message' => 'Category created successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category creation failed', [
                'vendor_id' => $this->vendor->id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to create category: ' . $e->getMessage());
        }
    }

    /**
     * Update category (WRITE to Magento first, then local)
     */
    public function updateCategory(string $uuid, array $data): array
    {
        $localCategory = Category::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        Log::info('Category update request', [
            'uuid' => $uuid,
            'magento_id' => $localCategory->magento_id,
            'data' => $data
        ]);

        DB::beginTransaction();

        try {
            $updatedMagentoCategory = $this->updateCategoryInMagento($localCategory->magento_id, $data);

            Log::info('Magento category update response', [
                'vendor_id' => $this->vendor->id,
                'magento_id' => $localCategory->magento_id,
                'response' => $updatedMagentoCategory,
            ]);

            if (empty($updatedMagentoCategory['id'])) {
                throw new \Exception('Magento returned no ID in update response');
            }

            $this->syncFromMagento($updatedMagentoCategory);

            DB::commit();

            return [
                'success' => true,
                'data' => [
                    'uuid' => $localCategory->uuid,
                    'name' => $updatedMagentoCategory['name'] ?? $data['name'],
                ],
                'message' => 'Category updated successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category update failed', [
                'vendor_id' => $this->vendor->id,
                'uuid' => $uuid,
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to update category: ' . $e->getMessage());
        }
    }

    /**
     * Delete category (DELETE from Magento first, then local)
     */
    public function deleteCategory(string $uuid): array
    {
        $localCategory = Category::forVendor($this->vendor->id)
            ->where('uuid', $uuid)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $deleted = $this->deleteCategoryFromMagento($localCategory->magento_id);

            if (!$deleted) {
                throw new \Exception('Magento returned false for category deletion — local record preserved');
            }

            $localCategory->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Category deleted successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category deletion failed', [
                'vendor_id' => $this->vendor->id,
                'uuid'      => $uuid,
                'error'     => $e->getMessage()
            ]);

            throw new \Exception('Failed to delete category: ' . $e->getMessage());
        }
    }

    /**
     * Sync all categories from Magento to local
     */
    public function syncAllCategories(): array
    {
        DB::beginTransaction();

        try {
            // Get all categories from Magento
            $magentoCategories = $this->magento()->get('categories');

            $syncedCount = 0;

            // Handle items array
            if (!empty($magentoCategories['items'])) {
                foreach ($magentoCategories['items'] as $magentoCategory) {
                    $this->syncFromMagento($magentoCategory);
                    $syncedCount++;
                }
            }

            // Handle children_data tree structure
            if (!empty($magentoCategories['children_data'])) {
                $this->syncCategoriesRecursive($magentoCategories['children_data'], null);
                $syncedCount = Category::forVendor($this->vendor->id)->count();
            }

            DB::commit();

            return [
                'success' => true,
                'message' => "Synced categories successfully",
                'data' => ['synced_count' => $syncedCount]
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Category sync failed', [
                'vendor_id' => $this->vendor->id,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to sync categories: ' . $e->getMessage());
        }
    }

    /**
     * Sync single category from Magento
     */
    public function syncSingleCategory(int $magentoId): ?Category
    {
        try {
            $magentoCategory = $this->magento()->get("categories/{$magentoId}");
            return $this->syncFromMagento($magentoCategory);
        } catch (\Exception $e) {
            Log::error('Failed to sync single category', [
                'magento_id' => $magentoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Assign product to category in Magento
     */
    public function assignProductToCategory(string $categoryUuid, string $productSku, int $position = 0): array
    {
        $category = Category::forVendor($this->vendor->id)
            ->where('uuid', $categoryUuid)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Build payload as per Magento API spec
            $payload = [
                'productLink' => [
                    'sku' => $productSku,
                    'position' => $position,
                    'category_id' => (string) $category->magento_id,
                ]
            ];

            // Assign in Magento
            $result = $this->magento()->post("categories/{$category->magento_id}/products", $payload);

            // Sync category from Magento to update local cache
            $this->syncSingleCategory($category->magento_id);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Product assigned to category successfully',
                'data' => $result
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Product assignment to category failed', [
                'vendor_id' => $this->vendor->id,
                'category_uuid' => $categoryUuid,
                'product_sku' => $productSku,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to assign product to category: ' . $e->getMessage());
        }
    }

    /**
     * Remove product from category in Magento
     */
    public function removeProductFromCategory(string $categoryUuid, string $productSku): array
    {
        $category = Category::forVendor($this->vendor->id)
            ->where('uuid', $categoryUuid)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Remove from Magento
            $result = $this->magento()->delete("categories/{$category->magento_id}/products/{$productSku}");

            // Sync category from Magento to update local cache
            $this->syncSingleCategory($category->magento_id);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Product removed from category successfully',
                'data' => $result
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Product removal from category failed', [
                'vendor_id' => $this->vendor->id,
                'category_uuid' => $categoryUuid,
                'product_sku' => $productSku,
                'error' => $e->getMessage()
            ]);

            throw new \Exception('Failed to remove product from category: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private Magento API Methods
    // -------------------------------------------------------------------------

    /**
     * Create category in Magento with complete payload structure
     */
    private function createCategoryInMagento(array $data): array
    {
        // Build custom attributes array based on Magento API spec
        $customAttributes = [];

        // Content Section
        if (!empty($data['description'])) {
            $customAttributes[] = [
                'attribute_code' => 'description',
                'value' => $data['description']
            ];
        }

        if (!empty($data['image'])) {
            $customAttributes[] = [
                'attribute_code' => 'image',
                'value' => $data['image']
            ];
        }

        if (!empty($data['landing_page'])) {
            $customAttributes[] = [
                'attribute_code' => 'landing_page',
                'value' => $data['landing_page']
            ];
        }

        // Display Section
        if (!empty($data['display_mode'])) {
            $customAttributes[] = [
                'attribute_code' => 'display_mode',
                'value' => $data['display_mode']
            ];
        }

        if (isset($data['is_anchor'])) {
            $customAttributes[] = [
                'attribute_code' => 'is_anchor',
                'value' => (string) $data['is_anchor']
            ];
        }

        if (!empty($data['available_sort_by']) && is_array($data['available_sort_by'])) {
            $customAttributes[] = [
                'attribute_code' => 'available_sort_by',
                'value' => $data['available_sort_by']
            ];
        }

        if (!empty($data['default_sort_by'])) {
            $customAttributes[] = [
                'attribute_code' => 'default_sort_by',
                'value' => $data['default_sort_by']
            ];
        }

        if (!empty($data['layered_navigation_price_step'])) {
            $customAttributes[] = [
                'attribute_code' => 'layered_navigation_price_step',
                'value' => (string) $data['layered_navigation_price_step']
            ];
        }

        // Search Engine Optimization Section
        if (!empty($data['url_key'])) {
            $customAttributes[] = [
                'attribute_code' => 'url_key',
                'value' => $data['url_key']
            ];
        } else {
            $customAttributes[] = [
                'attribute_code' => 'url_key',
                'value' => Str::slug($data['name'])
            ];
        }

        if (!empty($data['meta_title'])) {
            $customAttributes[] = [
                'attribute_code' => 'meta_title',
                'value' => $data['meta_title']
            ];
        }

        if (!empty($data['meta_keywords'])) {
            $customAttributes[] = [
                'attribute_code' => 'meta_keywords',
                'value' => $data['meta_keywords']
            ];
        }

        if (!empty($data['meta_description'])) {
            $customAttributes[] = [
                'attribute_code' => 'meta_description',
                'value' => $data['meta_description']
            ];
        }

        // Design Section (only if not using parent settings)
        if (empty($data['use_parent_settings'])) {
            if (!empty($data['custom_design'])) {
                $customAttributes[] = [
                    'attribute_code' => 'custom_design',
                    'value' => $data['custom_design']
                ];
            }

            if (!empty($data['page_layout'])) {
                $customAttributes[] = [
                    'attribute_code' => 'page_layout',
                    'value' => $data['page_layout']
                ];
            }

            if (isset($data['custom_apply_to_products'])) {
                $customAttributes[] = [
                    'attribute_code' => 'custom_apply_to_products',
                    'value' => (string) $data['custom_apply_to_products']
                ];
            }

            if (!empty($data['custom_layout_update'])) {
                $customAttributes[] = [
                    'attribute_code' => 'custom_layout_update',
                    'value' => $data['custom_layout_update']
                ];
            }
        }

        // Schedule Design Update Section
        if (!empty($data['custom_design_from'])) {
            $customAttributes[] = [
                'attribute_code' => 'custom_design_from',
                'value' => $data['custom_design_from']
            ];
        }

        if (!empty($data['custom_design_to'])) {
            $customAttributes[] = [
                'attribute_code' => 'custom_design_to',
                'value' => $data['custom_design_to']
            ];
        }

        // Build category payload as per Magento format
        $categoryPayload = [
            'category' => [
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
                'include_in_menu' => $data['include_in_menu'] ?? true,
                'position' => $data['position'] ?? 0,
            ]
        ];

        // Add custom attributes if any
        if (!empty($customAttributes)) {
            $categoryPayload['category']['custom_attributes'] = $customAttributes;
        }

        // Handle parent category
        if (isset($data['parent_id'])) {
            if (!empty($data['parent_id']) && $data['parent_id'] !== 'null') {
                $parentCategory = Category::forVendor($this->vendor->id)
                    ->where('uuid', $data['parent_id'])
                    ->first();

                if ($parentCategory && $parentCategory->magento_id) {
                    $categoryPayload['category']['parent_id'] = (int) $parentCategory->magento_id;
                }
            } else {
                $categoryPayload['category']['parent_id'] = 1; // Root category
            }
        }

        // Handle children data if provided
        if (!empty($data['children_data']) && is_array($data['children_data'])) {
            $categoryPayload['category']['children_data'] = $this->buildChildrenData($data['children_data']);
        }

        return $this->magento()->post('categories', $categoryPayload);
    }

    /**
     * Update category in Magento with complete payload
     */
    private function updateCategoryInMagento(string $magentoId, array $data): array
    {
        $categoryPayload = [
            'category' => [
                'id' => (int) $magentoId,
            ]
        ];

        // Basic fields
        if (isset($data['name'])) {
            $categoryPayload['category']['name'] = $data['name'];
        }

        if (isset($data['is_active'])) {
            $categoryPayload['category']['is_active'] = (bool) $data['is_active'];
        }

        if (isset($data['include_in_menu'])) {
            $categoryPayload['category']['include_in_menu'] = (bool) $data['include_in_menu'];
        }

        if (isset($data['position'])) {
            $categoryPayload['category']['position'] = (int) $data['position'];
        }

        // Handle parent change
        if (array_key_exists('parent_id', $data)) {
            if (!empty($data['parent_id']) && $data['parent_id'] !== 'null') {
                $parentCategory = Category::forVendor($this->vendor->id)
                    ->where('uuid', $data['parent_id'])
                    ->first();

                if ($parentCategory && $parentCategory->magento_id) {
                    $categoryPayload['category']['parent_id'] = (int) $parentCategory->magento_id;
                }
            } elseif ($data['parent_id'] === null || $data['parent_id'] === 'null') {
                $categoryPayload['category']['parent_id'] = 1;
            }
        }

        // Build custom attributes
        $customAttributes = [];

        // Content Section
        if (array_key_exists('description', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'description',
                'value' => $data['description'] ?? ''
            ];
        }

        if (array_key_exists('image', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'image',
                'value' => $data['image'] ?? ''
            ];
        }

        if (array_key_exists('landing_page', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'landing_page',
                'value' => $data['landing_page'] ?? ''
            ];
        }

        // Display Section
        if (array_key_exists('display_mode', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'display_mode',
                'value' => $data['display_mode']
            ];
        }

        if (array_key_exists('is_anchor', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'is_anchor',
                'value' => (string) $data['is_anchor']
            ];
        }

        if (array_key_exists('available_sort_by', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'available_sort_by',
                'value' => $data['available_sort_by'] ?? []
            ];
        }

        if (array_key_exists('default_sort_by', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'default_sort_by',
                'value' => $data['default_sort_by']
            ];
        }

        if (array_key_exists('layered_navigation_price_step', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'layered_navigation_price_step',
                'value' => (string) $data['layered_navigation_price_step']
            ];
        }

        // SEO Section
        if (array_key_exists('url_key', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'url_key',
                'value' => $data['url_key'] ?? Str::slug($data['name'] ?? '')
            ];
        }

        if (array_key_exists('meta_title', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'meta_title',
                'value' => $data['meta_title'] ?? ''
            ];
        }

        if (array_key_exists('meta_keywords', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'meta_keywords',
                'value' => $data['meta_keywords'] ?? ''
            ];
        }

        if (array_key_exists('meta_description', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'meta_description',
                'value' => $data['meta_description'] ?? ''
            ];
        }

        // Design Section
        if (empty($data['use_parent_settings'])) {
            if (array_key_exists('custom_design', $data)) {
                $customAttributes[] = [
                    'attribute_code' => 'custom_design',
                    'value' => $data['custom_design'] ?? ''
                ];
            }

            if (array_key_exists('page_layout', $data)) {
                $customAttributes[] = [
                    'attribute_code' => 'page_layout',
                    'value' => $data['page_layout'] ?? ''
                ];
            }

            if (array_key_exists('custom_apply_to_products', $data)) {
                $customAttributes[] = [
                    'attribute_code' => 'custom_apply_to_products',
                    'value' => (string) $data['custom_apply_to_products']
                ];
            }

            if (array_key_exists('custom_layout_update', $data)) {
                $customAttributes[] = [
                    'attribute_code' => 'custom_layout_update',
                    'value' => $data['custom_layout_update'] ?? ''
                ];
            }
        }

        // Schedule Design Update
        if (array_key_exists('custom_design_from', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'custom_design_from',
                'value' => $data['custom_design_from'] ?? ''
            ];
        }

        if (array_key_exists('custom_design_to', $data)) {
            $customAttributes[] = [
                'attribute_code' => 'custom_design_to',
                'value' => $data['custom_design_to'] ?? ''
            ];
        }

        if (!empty($customAttributes)) {
            $categoryPayload['category']['custom_attributes'] = $customAttributes;
        }

        // Remove empty arrays
        $categoryPayload['category'] = array_filter($categoryPayload['category'], function ($value) {
            return !is_array($value) || !empty($value);
        });

        Log::info('Magento update payload', $categoryPayload);

        return $this->magento()->put("categories/{$magentoId}", $categoryPayload);
    }

    /**
     * Delete category from Magento
     */
    private function deleteCategoryFromMagento(string $magentoId): bool
    {
        try {
            $this->magento()->delete("categories/{$magentoId}");
            return true;
        } catch (\Exception $e) {
            Log::error('Magento category deletion error', [
                'magento_id' => $magentoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Build children data for nested category creation
     */
    private function buildChildrenData(array $children): array
    {
        $childrenData = [];

        foreach ($children as $child) {
            $childData = [
                'name' => $child['name'],
                'is_active' => $child['is_active'] ?? true,
                'include_in_menu' => $child['include_in_menu'] ?? true,
                'position' => $child['position'] ?? 0,
            ];

            // Add custom attributes for child
            $customAttributes = [];

            $urlKey = $child['url_key'] ?? Str::slug($child['name']);
            $customAttributes[] = [
                'attribute_code' => 'url_key',
                'value' => $urlKey
            ];

            if (!empty($child['description'])) {
                $customAttributes[] = [
                    'attribute_code' => 'description',
                    'value' => $child['description']
                ];
            }

            if (!empty($customAttributes)) {
                $childData['custom_attributes'] = $customAttributes;
            }

            // Recursively handle nested children
            if (!empty($child['children_data'])) {
                $childData['children_data'] = $this->buildChildrenData($child['children_data']);
            }

            $childrenData[] = $childData;
        }

        return $childrenData;
    }

    // -------------------------------------------------------------------------
    // Private Local DB Sync Methods
    // -------------------------------------------------------------------------

    /**
     * Sync single category from Magento data to local DB
     */
    private function syncFromMagento(array $magentoCategory): Category
    {
        // Extract custom attributes
        $customAttributes = $this->extractCustomAttributes($magentoCategory['custom_attributes'] ?? []);

        // Find parent category in local DB
        $parentId = null;
        $parentPath = null;

        if (!empty($magentoCategory['parent_id']) && $magentoCategory['parent_id'] != 0) {
            $parentCategory = Category::forVendor($this->vendor->id)
                ->where('magento_id', $magentoCategory['parent_id'])
                ->first();

            if ($parentCategory) {
                $parentId = $parentCategory->id;
                $parentPath = $parentCategory->parent_path
                    ? $parentCategory->parent_path . '/' . $parentCategory->magento_id
                    : (string) $parentCategory->magento_id;
            }
        }

        // Generate unique slug from url_key or name
        $slug = $customAttributes['url_key'] ?? Str::slug($magentoCategory['name']);
        $baseSlug = $slug;
        $counter = 1;

        while (Category::forVendor($this->vendor->id)
            ->where('slug', $slug)
            ->where('magento_id', '!=', $magentoCategory['id'])
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $counter++;
        }

        // Extract image URL if exists
        $imageUrl = null;
        if (!empty($customAttributes['image'])) {
            $imageUrl = $customAttributes['image'];
        }

        // Prepare category data
        $categoryData = [
            'name' => $magentoCategory['name'],
            'slug' => $slug,
            'description' => $customAttributes['description'] ?? null,
            'parent_id' => $parentId,
            'parent_path' => $parentPath,
            'position' => $magentoCategory['position'] ?? 0,
            'is_active' => $magentoCategory['is_active'] ?? true,
            'include_in_menu' => $magentoCategory['include_in_menu'] ?? true,
            'level' => $magentoCategory['level'] ?? 0,
            'children_count' => $magentoCategory['children_count'] ?? 0,
            'image_url' => $imageUrl,
            'meta_title' => $customAttributes['meta_title'] ?? null,
            'meta_description' => $customAttributes['meta_description'] ?? null,
            'meta_keywords' => $customAttributes['meta_keywords'] ?? null,
            'display_mode' => $customAttributes['display_mode'] ?? null,
            'is_anchor' => $customAttributes['is_anchor'] ?? null,
            'available_sort_by' => isset($customAttributes['available_sort_by']) ? json_encode($customAttributes['available_sort_by']) : null,
            'default_sort_by' => $customAttributes['default_sort_by'] ?? null,
            'landing_page' => $customAttributes['landing_page'] ?? null,
            'custom_design' => $customAttributes['custom_design'] ?? null,
            'page_layout' => $customAttributes['page_layout'] ?? null,
            'custom_apply_to_products' => $customAttributes['custom_apply_to_products'] ?? null,
            'custom_layout_update' => $customAttributes['custom_layout_update'] ?? null,
            'custom_design_from' => $customAttributes['custom_design_from'] ?? null,
            'custom_design_to' => $customAttributes['custom_design_to'] ?? null,
            'magento_data' => $magentoCategory,
            'last_synced_at' => now(),
            'magento_updated_at' => $magentoCategory['updated_at'] ?? now()
        ];

        // Update or create local category
        return Category::updateOrCreate(
            [
                'vendor_id' => $this->vendor->id,
                'magento_id' => $magentoCategory['id']
            ],
            $categoryData
        );
    }

    /**
     * Extract custom attributes from Magento category
     */
    private function extractCustomAttributes(array $customAttributes): array
    {
        $extracted = [];

        foreach ($customAttributes as $attr) {
            if (isset($attr['attribute_code']) && isset($attr['value'])) {
                $extracted[$attr['attribute_code']] = $attr['value'];
            }
        }

        return $extracted;
    }

    /**
     * Recursively sync categories from Magento tree structure
     */
    private function syncCategoriesRecursive(array $categories, $parentLocalId): void
    {
        foreach ($categories as $magentoCat) {
            $localCategory = $this->syncFromMagento($magentoCat);

            // Override parent if needed
            if ($parentLocalId && $localCategory->parent_id !== $parentLocalId) {
                $localCategory->parent_id = $parentLocalId;
                $localCategory->save();
            }

            // Process children recursively
            if (!empty($magentoCat['children_data'])) {
                $this->syncCategoriesRecursive($magentoCat['children_data'], $localCategory->id);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private Helper Methods
    // -------------------------------------------------------------------------

    /**
     * Build tree structure from categories
     */
    private function buildTree($categories): array
    {
        $tree = [];

        foreach ($categories as $category) {
            $node = [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'slug' => $category->slug,
                'level' => $category->level,
                'position' => $category->position,
                'is_active' => $category->is_active,
                'include_in_menu' => $category->include_in_menu,
                'children_count' => $category->children->count(),
            ];

            if ($category->children->isNotEmpty()) {
                $node['children'] = $this->buildTree($category->children);
            }

            $tree[] = $node;
        }

        return $tree;
    }

    /**
     * Format children categories
     */
    private function formatChildren($children): array
    {
        $formatted = [];
        foreach ($children as $child) {
            $formatted[] = [
                'uuid' => $child->uuid,
                'name' => $child->name,
                'slug' => $child->slug,
                'level' => $child->level,
                'is_active' => $child->is_active,
                'position' => $child->position,
            ];
        }
        return $formatted;
    }
}
