<?php

namespace App\Services;

use App\Services\Integration\MagentoService;
use App\Models\AttributeSets;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class AttributeSetService
{
    protected MagentoService $magentoService;

    public function __construct(MagentoService $magentoService)
    {
        $this->magentoService = $magentoService;
    }

    /**
     * Get all attribute sets from Magento with pagination
     */
    public function getAllAttributeSets(int $page = 1, int $pageSize = 20, ?string $searchQuery = null): array
    {
        $params = [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]' => $pageSize,
        ];

        if ($searchQuery) {
            $params['searchCriteria[filterGroups][0][filters][0][field]'] = 'attribute_set_name';
            $params['searchCriteria[filterGroups][0][filters][0][value]'] = $searchQuery;
            $params['searchCriteria[filterGroups][0][filters][0][conditionType]'] = 'like';
        }

        return $this->magentoService->get('products/attribute-sets/sets/list', $params);
    }

    /**
     * Get single attribute set by ID from Magento
     */
    public function getAttributeSet(int $attributeSetId): array
    {
        return $this->magentoService->get("products/attribute-sets/{$attributeSetId}");
    }


    /**
     * Create attribute set in Magento
     */
    public function createAttributeSet(array $data): array
    {
        // Build the payload with correct Magento 2 structure
        $payload = [
            'attributeSet' => [
                'attribute_set_name' => $data['attribute_set_name'],
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'entity_type_id' => (int) ($data['entity_type_id'] ?? 4),
            ],
            'skeletonId' => (int) ($data['skeleton_id'] ?? 4) // skeletonId at root level
        ];

        return $this->magentoService->post('products/attribute-sets', $payload);
    }

    /**
     * Update attribute set in Magento
     */
    public function updateAttributeSet(int $attributeSetId, array $data): array
    {
        $payload = [
            'attributeSet' => [
                'attribute_set_name' => $data['attribute_set_name'],
                'sort_order' => $data['sort_order'] ?? 0,
            ]
        ];

        return $this->magentoService->put("products/attribute-sets/{$attributeSetId}", $payload);
    }

    /**
     * Delete attribute set from Magento
     */
    public function deleteAttributeSet(int $attributeSetId): array
    {
        return $this->magentoService->delete("products/attribute-sets/{$attributeSetId}");
    }

    /**
     * Get all attributes assigned to an attribute set
     */
    public function getAttributeSetAttributes(int $attributeSetId): array
    {
        return $this->magentoService->get("products/attribute-sets/{$attributeSetId}/attributes");
    }

    /**
     * Assign attribute to attribute set
     */
    public function assignAttributeToSet(int $attributeSetId, int $attributeId, int $attributeGroupId, int $sortOrder = 0): array
    {
        $payload = [
            'attributeId' => $attributeId,
            'attributeGroupId' => $attributeGroupId,
            'sortOrder' => $sortOrder,
        ];

        return $this->magentoService->post("products/attribute-sets/{$attributeSetId}/attributes", $payload);
    }

    /**
     * Remove attribute from attribute set
     */
    public function removeAttributeFromSet(int $attributeSetId, int $attributeId): array
    {
        try {
            $response = $this->magentoService->delete("products/attribute-sets/{$attributeSetId}/attributes/{$attributeId}");
            return $response;
        } catch (\Exception $e) {
            // Re-throw the exception to be handled in controller
            throw $e;
        }
    }

    /**
     * Get attribute groups for an attribute set
     * Magento 2 API endpoint: /V1/products/attribute-sets/groups/list
     */
    public function getAttributeGroups(int $attributeSetId): array
    {
        try {
            // Try the correct Magento 2 endpoint for getting groups
            $response = $this->magentoService->get('products/attribute-sets/groups/list', [
                'searchCriteria[filterGroups][0][filters][0][field]' => 'attribute_set_id',
                'searchCriteria[filterGroups][0][filters][0][value]' => $attributeSetId,
                'searchCriteria[filterGroups][0][filters][0][condition_type]' => 'eq',
            ]);

            // Handle different response formats
            if (isset($response['items'])) {
                return $response['items'];
            }

            return $response;
        } catch (\Exception $e) {
            // If the above fails, try alternative endpoint
            Log::warning('Failed to get attribute groups via list endpoint, trying alternative', [
                'attribute_set_id' => $attributeSetId,
                'error' => $e->getMessage(),
            ]);

            // Alternative: Try to get groups directly (some Magento versions)
            try {
                $response = $this->magentoService->get("products/attribute-sets/{$attributeSetId}/groups");
                if (isset($response['items'])) {
                    return $response['items'];
                }
                return $response;
            } catch (\Exception $e2) {
                Log::error('Failed to get attribute groups from both endpoints', [
                    'attribute_set_id' => $attributeSetId,
                    'error' => $e2->getMessage(),
                ]);
                return []; // Return empty array instead of failing
            }
        }
    }

    /**
     * Create attribute group within an attribute set
     */
    public function createAttributeGroup(int $attributeSetId, string $groupName, int $sortOrder = 0): array
    {
        $payload = [
            'group' => [
                'attribute_group_name' => $groupName,
                'sort_order' => $sortOrder,
                'attribute_set_id' => $attributeSetId,
            ]
        ];

        return $this->magentoService->post('products/attribute-sets/groups', $payload);
    }

    /**
     * Update attribute group
     */
    public function updateAttributeGroup(int $groupId, string $groupName, int $sortOrder = null): array
    {
        $payload = [
            'group' => [
                'attribute_group_name' => $groupName,
            ]
        ];

        if ($sortOrder !== null) {
            $payload['group']['sort_order'] = $sortOrder;
        }

        return $this->magentoService->put("products/attribute-sets/groups/{$groupId}", $payload);
    }

    /**
     * Delete attribute group
     */
    public function deleteAttributeGroup(int $groupId): array
    {
        return $this->magentoService->delete("products/attribute-sets/groups/{$groupId}");
    }

    /**
     * Sync single attribute set from Magento to local DB
     */
    public function syncFromMagentoToLocal(string $vendorUuid, int $magentoAttributeSetId): AttributeSets
    {
        // Get vendor ID from UUID
        $vendor = \App\Models\Vendor\Vendor::where('uuid', $vendorUuid)->firstOrFail();

        // Fetch from Magento
        $magentoData = $this->getAttributeSet($magentoAttributeSetId);

        // Fetch attributes for this attribute set
        $attributes = $this->getAttributeSetAttributes($magentoAttributeSetId);

        // Fetch groups for this attribute set (handle gracefully if fails)
        $groups = [];
        try {
            $groups = $this->getAttributeGroups($magentoAttributeSetId);
        } catch (\Exception $e) {
            Log::warning('Could not fetch groups for attribute set', [
                'magento_attr_set_id' => $magentoAttributeSetId,
                'error' => $e->getMessage(),
            ]);
            // Continue with empty groups
        }

        // Update or create local record
        $attributeSets = AttributeSets::updateOrCreate(
            [
                'vendor_id' => $vendor->id,
                'magento_attr_set_id' => $magentoAttributeSetId,
            ],
            [
                'attribute_set_name' => $magentoData['attribute_set_name'],
                'sort_order' => $magentoData['sort_order'] ?? 0,
                'entity_type_id' => $magentoData['entity_type_id'] ?? 4,
                'assigned_attribute_ids' => collect($attributes)->pluck('attribute_id')->toArray(),
                'attribute_group_data' => !empty($groups) ? $groups : null,
                'last_synced_at' => now(),
                'sync_status' => 'synced',
                'sync_error_message' => null,
            ]
        );

        Log::info('Attribute set synced from Magento to local', [
            'vendor_uuid' => $vendorUuid,
            'magento_attr_set_id' => $magentoAttributeSetId,
            'local_id' => $attributeSets->id,
            'groups_count' => count($groups),
            'attributes_count' => count($attributes),
        ]);

        return $attributeSets;
    }

    /**
     * Sync all attribute sets from Magento to local DB
     */
    public function bulkSyncFromMagentoToLocal(string $vendorUuid, int $pageSize = 100): array
    {
        $syncedCount = 0;
        $errors = [];
        $page = 1;

        try {
            do {
                $response = $this->getAllAttributeSets($page, $pageSize);
                $items = $response['items'] ?? $response;

                if (empty($items)) {
                    break;
                }

                foreach ($items as $magentoSet) {
                    try {
                        $attributeSetId = $magentoSet['attribute_set_id'] ?? $magentoSet['attributeSetId'] ?? null;

                        if ($attributeSetId) {
                            $this->syncFromMagentoToLocal($vendorUuid, $attributeSetId);
                            $syncedCount++;
                        }
                    } catch (\Exception $e) {
                        $errors[] = [
                            'attribute_set' => $magentoSet,
                            'error' => $e->getMessage(),
                        ];
                        Log::error('Failed to sync attribute set from Magento', [
                            'vendor_uuid' => $vendorUuid,
                            'attribute_set' => $magentoSet,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $page++;
                $totalCount = $response['total_count'] ?? count($items);
            } while (($page - 1) * $pageSize < $totalCount);
        } catch (\Exception $e) {
            Log::error('Bulk sync failed', [
                'vendor_uuid' => $vendorUuid,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return [
            'synced_count' => $syncedCount,
            'errors' => $errors,
        ];
    }
    /**
     * Push local attribute set to Magento (create or update)
     */
    public function pushLocalToMagento(AttributeSets $attributeSets, ?int $skeletonId = null): array
    {
        try {
            if ($attributeSets->magento_attr_set_id) {
                // Update existing
                $result = $this->updateAttributeSet(
                    $attributeSets->magento_attr_set_id,
                    [
                        'attribute_set_name' => $attributeSets->attribute_set_name,
                        'sort_order' => $attributeSets->sort_order,
                    ]
                );

                $attributeSets->update([
                    'last_synced_at' => now(),
                    'sync_status' => 'synced',
                    'sync_error_message' => null,
                ]);

                return [
                    'success' => true,
                    'action' => 'updated',
                    'magento_attr_set_id' => $attributeSets->magento_attr_set_id,
                    'data' => $result,
                ];
            } else {
                // Create new - require skeleton_id
                $skeletonId = $skeletonId ?? 4; // Default to 4 if not provided

                $result = $this->createAttributeSet([
                    'attribute_set_name' => $attributeSets->attribute_set_name,
                    'sort_order' => $attributeSets->sort_order,
                    'entity_type_id' => $attributeSets->entity_type_id,
                    'skeleton_id' => $skeletonId,
                ]);

                $magentoId = $result['attribute_set_id'] ?? $result['attributeSetId'] ?? null;

                if ($magentoId) {
                    $attributeSets->update([
                        'magento_attr_set_id' => $magentoId,
                        'last_synced_at' => now(),
                        'sync_status' => 'synced',
                        'sync_error_message' => null,
                    ]);
                }

                return [
                    'success' => true,
                    'action' => 'created',
                    'magento_attr_set_id' => $magentoId,
                    'data' => $result,
                ];
            }
        } catch (\Exception $e) {
            $attributeSets->update([
                'sync_status' => 'failed',
                'sync_error_message' => $e->getMessage(),
                'sync_attempts' => $attributeSets->sync_attempts + 1,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get attribute set with full details (including attributes and groups)
     */
    public function getFullAttributeSetDetails(int $AttributeSetId): array
    {
        $attributeSet = $this->getAttributeSet($AttributeSetId);
        $attributes = $this->getAttributeSetAttributes($AttributeSetId);
        $groups = $this->getAttributeGroups($AttributeSetId);

        return [
            'attribute_set' => $attributeSet,
            'attributes' => $attributes,
            'groups' => $groups,
        ];
    }

    /**
     * Get all attribute sets with their attributes for a vendor
     */
    public function getAllAttributeSetsWithDetails(string $vendorUuid): Collection
    {
        $vendor = \App\Models\Vendor\Vendor::where('uuid', $vendorUuid)->firstOrFail();

        $localSets = AttributeSets::where('vendor_id', $vendor->id)
            ->whereNotNull('magento_attr_set_id')
            ->get();

        $result = collect();

        foreach ($localSets as $localSet) {
            try {
                if ($localSet->magento_attr_set_id) {
                    $details = $this->getFullAttributeSetDetails($localSet->magento_attr_set_id);
                    $result->push([
                        'local_record' => $localSet,
                        'magento_data' => $details,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch attribute set details from Magento', [
                    'vendor_uuid' => $vendorUuid,
                    'local_id' => $localSet->id,
                    'magento_id' => $localSet->magento_attr_set_id,
                    'error' => $e->getMessage(),
                ]);

                $result->push([
                    'local_record' => $localSet,
                    'magento_data' => null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }


    /**
     * Get all product attributes from Magento
     */
    public function getAllProductAttributes(): array
    {
        return $this->magentoService->get('products/attributes', [
            'searchCriteria[pageSize]' => 500
        ]);
    }

    /**
     * Get attribute structure for an attribute set
     */
    public function getAttributeSetStructure(int $attributeSetId): array
    {
        // Get attribute set details
        $attributeSet = $this->getAttributeSet($attributeSetId);

        // Get all groups for this attribute set
        $groups = $this->getAttributeGroups($attributeSetId);

        // Get assigned attributes for this attribute set
        $assignedAttributes = $this->getAttributeSetAttributes($attributeSetId);

        // Get all product attributes to find unassigned ones
        $allAttributes = $this->getAllProductAttributes();

        $assignedAttributeCodes = [];
        $groupsWithAttributes = [];

        // Organize assigned attributes by group
        foreach ($assignedAttributes as $attribute) {
            $assignedAttributeCodes[] = $attribute['attribute_code'];

            $groupId = $attribute['attribute_group_id'] ?? null;
            if ($groupId) {
                if (!isset($groupsWithAttributes[$groupId])) {
                    $groupsWithAttributes[$groupId] = [];
                }
                $groupsWithAttributes[$groupId][] = [
                    'attribute_id' => $attribute['attribute_id'],
                    'attribute_code' => $attribute['attribute_code'],
                    'frontend_label' => $attribute['frontend_label'] ?? $attribute['attribute_code'],
                    'sort_order' => $attribute['sort_order'] ?? 0,
                ];
            }
        }

        // Build groups structure
        $groupData = [];
        foreach ($groups as $group) {
            $groupId = $group['attribute_group_id'];
            $groupData[] = [
                'attribute_group_id' => $groupId,
                'attribute_group_name' => $group['attribute_group_name'],
                'sort_order' => $group['sort_order'] ?? 0,
                'attributes' => $groupsWithAttributes[$groupId] ?? []
            ];
        }

        // Find unassigned attributes
        $unassignedAttributes = [];
        foreach ($allAttributes as $attribute) {
            if (!in_array($attribute['attribute_code'], $assignedAttributeCodes)) {
                $unassignedAttributes[] = [
                    'attribute_id' => $attribute['attribute_id'],
                    'attribute_code' => $attribute['attribute_code'],
                    'frontend_label' => $attribute['frontend_label'] ?? $attribute['attribute_code'],
                    'backend_type' => $attribute['backend_type'] ?? 'varchar',
                ];
            }
        }

        return [
            'attribute_set_id' => $attributeSetId,
            'attribute_set_name' => $attributeSet['attribute_set_name'],
            'groups' => $groupData,
            'unassigned_attributes' => $unassignedAttributes
        ];
    }

    /**
     * Get all attributes assigned to an attribute set WITH group information
     */
    public function getAttributeSetAttributesWithGroups(int $attributeSetId): array
    {
        // This endpoint returns attributes with their group assignments
        return $this->magentoService->get("products/attribute-sets/{$attributeSetId}/attributes");
    }

    /**
     * Get single attribute details from Magento
     */
    public function getAttribute(int $attributeId): array
    {
        return $this->magentoService->get("products/attributes/{$attributeId}");
    }

    public function getAttributes(array $attributeIds): array
    {
        // Magento's REST API doesn't have a batch endpoint directly
        // But we can use search criteria
        $searchCriteria = [
            'searchCriteria[filterGroups][0][filters][0][field]' => 'attribute_id',
            'searchCriteria[filterGroups][0][filters][0][value]' => implode(',', $attributeIds),
            'searchCriteria[filterGroups][0][filters][0][condition_type]' => 'in',
            'searchCriteria[pageSize]' => count($attributeIds),
        ];

        return $this->magentoService->get('products/attributes', $searchCriteria);
    }
}
