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
        return new MagentoService($vendor);
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
    public function getCategories(?int $parentId = null, bool $includeCount = false): array
    {
        $query = Category::forVendor($this->vendor->id)
            ->with('children')
            ->active();

        if ($parentId !== null) {
            $query->where('parent_id', $parentId);
        } else {
            $query->rootLevel();
        }

        $categories = $query->orderBy('position')->get();

        $result = [];
        foreach ($categories as $category) {
            $data = [
                'id' => $category->uuid,
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
            'id' => $category->uuid,
            'internal_id' => $category->id,
            'magento_id' => $category->magento_id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'level' => $category->level,
            'is_active' => $category->is_active,
            'meta_title' => $category->meta_title,
            'meta_description' => $category->meta_description,
            'parent' => $category->parent ? [
                'id' => $category->parent->uuid,
                'name' => $category->parent->name,
                'slug' => $category->parent->slug,
            ] : null,
        ];
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
            // 1. Create in Magento with correct payload structure
            $magentoCategory = $this->createCategoryInMagento($data);

            // 2. Sync to local DB
            $localCategory = $this->syncFromMagento($magentoCategory);

            DB::commit();

            return [
                'success' => true,
                'data' => [
                    'id' => $localCategory->uuid,
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

        // Log the incoming update request
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
                    'id' => $localCategory->uuid,
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
            $magentoCategories = $this->magento()->getCategories();

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

    // -------------------------------------------------------------------------
    // Private Magento API Methods (With Correct Payload Structure)
    // -------------------------------------------------------------------------

    /**
     * Create category in Magento with correct payload structure
     */
    private function createCategoryInMagento(array $data): array
    {
        // Build custom attributes array
        $customAttributes = [];

        // Add url_key (required for SEO)
        $urlKey = $data['url_key'] ?? Str::slug($data['name']);
        $customAttributes[] = [
            'attribute_code' => 'url_key',
            'value' => $urlKey
        ];

        // Add description if provided
        if (!empty($data['description'])) {
            $customAttributes[] = [
                'attribute_code' => 'description',
                'value' => $data['description']
            ];
        }

        // Add meta title if provided
        if (!empty($data['meta_title'])) {
            $customAttributes[] = [
                'attribute_code' => 'meta_title',
                'value' => $data['meta_title']
            ];
        }

        // Add meta description if provided
        if (!empty($data['meta_description'])) {
            $customAttributes[] = [
                'attribute_code' => 'meta_description',
                'value' => $data['meta_description']
            ];
        }

        // Build category payload as per Magento format
        $categoryPayload = [
            'category' => [
                'name' => $data['name'],
                'is_active' => $data['is_active'] ?? true,
                'include_in_menu' => $data['include_in_menu'] ?? true,
                'position' => $data['position'] ?? 0,
                'custom_attributes' => $customAttributes
            ]
        ];

        // Handle parent category (parent_id should be Magento ID)
        if (!empty($data['parent_id'])) {
            $parentCategory = Category::forVendor($this->vendor->id)
                ->where('uuid', $data['parent_id'])
                ->first();

            if ($parentCategory && $parentCategory->magento_id) {
                $categoryPayload['category']['parent_id'] = (int) $parentCategory->magento_id;
            }
        }

        // Handle children data if provided (for nested category creation)
        if (!empty($data['children_data']) && is_array($data['children_data'])) {
            $categoryPayload['category']['children_data'] = $this->buildChildrenData($data['children_data']);
        }

        // Use public MagentoService method
        return $this->magento()->createCategory($categoryPayload);
    }

    /**
     * Update category in Magento
     */
    private function updateCategoryInMagento(string $magentoId, array $data): array
    {
        // Minimal payload - only send what's needed
        $categoryPayload = [
            'category' => [
                'id' => (int) $magentoId,
            ]
        ];

        // Only add fields if they exist in the request
        if (isset($data['name'])) {
            $categoryPayload['category']['name'] = $data['name'];
        }

        if (isset($data['is_active'])) {
            $categoryPayload['category']['is_active'] = $data['is_active'];
        }

        if (isset($data['include_in_menu'])) {
            $categoryPayload['category']['include_in_menu'] = $data['include_in_menu'];
        }

        if (isset($data['position'])) {
            $categoryPayload['category']['position'] = (int) $data['position'];
        }

        // Handle parent change
        if (isset($data['parent_id'])) {
            if (!empty($data['parent_id'])) {
                $parentCategory = Category::forVendor($this->vendor->id)
                    ->where('uuid', $data['parent_id'])
                    ->first();

                if ($parentCategory && $parentCategory->magento_id) {
                    $categoryPayload['category']['parent_id'] = (int) $parentCategory->magento_id;
                }
            } elseif ($data['parent_id'] === null || $data['parent_id'] === 'null') {
                $categoryPayload['category']['parent_id'] = 1; // Root category
            }
        }

        // Build custom attributes - ONLY these fields
        $customAttributes = [];

        if (isset($data['description']) && !empty($data['description'])) {
            $customAttributes[] = [
                'attribute_code' => 'description',
                'value' => $data['description']
            ];
        }

        if (isset($data['meta_title']) && !empty($data['meta_title'])) {
            $customAttributes[] = [
                'attribute_code' => 'meta_title',
                'value' => $data['meta_title']
            ];
        }

        if (isset($data['meta_description']) && !empty($data['meta_description'])) {
            $customAttributes[] = [
                'attribute_code' => 'meta_description',
                'value' => $data['meta_description']
            ];
        }

        if (isset($data['url_key']) && !empty($data['url_key'])) {
            $customAttributes[] = [
                'attribute_code' => 'url_key',
                'value' => $data['url_key']
            ];
        }

        if (!empty($customAttributes)) {
            $categoryPayload['category']['custom_attributes'] = $customAttributes;
        }

        // Remove any empty arrays that might cause issues
        $categoryPayload['category'] = array_filter($categoryPayload['category'], function ($value) {
            return !is_array($value) || !empty($value);
        });

        \Log::info('Clean payload for Magento', $categoryPayload);

        // Try direct update without store ID
        return $this->magento()->updateCategory((int) $magentoId, $categoryPayload);
    }

    private function deleteCategoryFromMagento(string $magentoId): bool
    {
        try {
            $response = $this->magento()->deleteCategory((int) $magentoId);

            // Magento returns empty array on successful delete (200 OK)
            // Or sometimes returns ['success' => true]
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
            'meta_title' => $customAttributes['meta_title'] ?? null,
            'meta_description' => $customAttributes['meta_description'] ?? null,
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
