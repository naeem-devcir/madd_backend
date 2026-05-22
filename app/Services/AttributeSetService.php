<?php

namespace App\Services;

use App\Services\Integration\MagentoService;
use App\Models\MagentoAttributeSet;
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
        $payload = [
            'attributeSet' => [
                'attribute_set_name' => $data['attribute_set_name'],
                'sort_order' => $data['sort_order'] ?? 0,
                'entity_type_id' => $data['entity_type_id'] ?? 4, // 4 = catalog_product
            ]
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
        return $this->magentoService->delete("products/attribute-sets/{$attributeSetId}/attributes/{$attributeId}");
    }
    
    /**
     * Get attribute groups for an attribute set
     */
    public function getAttributeGroups(int $attributeSetId): array
    {
        $response = $this->magentoService->get("products/attribute-sets/{$attributeSetId}/groups");
        
        // Handle different response formats
        if (isset($response['items'])) {
            return $response['items'];
        }
        
        return $response;
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
    public function syncFromMagentoToLocal(string $vendorUuid, int $magentoAttributeSetId): MagentoAttributeSet
    {
        // Get vendor ID from UUID
        $vendor = \App\Models\Vendor\Vendor::where('uuid', $vendorUuid)->firstOrFail();
        
        // Fetch from Magento
        $magentoData = $this->getAttributeSet($magentoAttributeSetId);
        
        // Fetch attributes for this attribute set
        $attributes = $this->getAttributeSetAttributes($magentoAttributeSetId);
        
        // Fetch groups for this attribute set
        $groups = $this->getAttributeGroups($magentoAttributeSetId);
        
        // Update or create local record
        $attributeSet = MagentoAttributeSet::updateOrCreate(
            [
                'vendor_id' => $vendor->id,
                'magento_attr_set_id' => $magentoAttributeSetId,
            ],
            [
                'attribute_set_name' => $magentoData['attribute_set_name'],
                'sort_order' => $magentoData['sort_order'] ?? 0,
                'entity_type_id' => $magentoData['entity_type_id'] ?? 4,
                'assigned_attribute_ids' => collect($attributes)->pluck('attribute_id')->toArray(),
                'attribute_group_data' => $groups,
                'last_synced_at' => now(),
                'sync_status' => 'synced',
                'sync_error_message' => null,
            ]
        );
        
        Log::info('Attribute set synced from Magento to local', [
            'vendor_uuid' => $vendorUuid,
            'magento_attr_set_id' => $magentoAttributeSetId,
            'local_id' => $attributeSet->id,
        ]);
        
        return $attributeSet;
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
    public function pushLocalToMagento(MagentoAttributeSet $attributeSet): array
    {
        try {
            if ($attributeSet->magento_attr_set_id) {
                // Update existing
                $result = $this->updateAttributeSet(
                    $attributeSet->magento_attr_set_id,
                    [
                        'attribute_set_name' => $attributeSet->attribute_set_name,
                        'sort_order' => $attributeSet->sort_order,
                    ]
                );
                
                $attributeSet->update([
                    'last_synced_at' => now(),
                    'sync_status' => 'synced',
                    'sync_error_message' => null,
                ]);
                
                return [
                    'success' => true,
                    'action' => 'updated',
                    'magento_attr_set_id' => $attributeSet->magento_attr_set_id,
                    'data' => $result,
                ];
            } else {
                // Create new
                $result = $this->createAttributeSet([
                    'attribute_set_name' => $attributeSet->attribute_set_name,
                    'sort_order' => $attributeSet->sort_order,
                    'entity_type_id' => $attributeSet->entity_type_id,
                ]);
                
                $magentoId = $result['attribute_set_id'] ?? $result['attributeSetId'] ?? null;
                
                if ($magentoId) {
                    $attributeSet->update([
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
            $attributeSet->update([
                'sync_status' => 'failed',
                'sync_error_message' => $e->getMessage(),
                'sync_attempts' => $attributeSet->sync_attempts + 1,
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
    public function getFullAttributeSetDetails(int $magentoAttributeSetId): array
    {
        $attributeSet = $this->getAttributeSet($magentoAttributeSetId);
        $attributes = $this->getAttributeSetAttributes($magentoAttributeSetId);
        $groups = $this->getAttributeGroups($magentoAttributeSetId);
        
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
        
        $localSets = MagentoAttributeSet::where('vendor_id', $vendor->id)
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
}