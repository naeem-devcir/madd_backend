<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Models\MagentoAttributeSet;
use App\Services\AttributeSetService;
use App\Services\Integration\MagentoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class AdminAttributeSetController extends Controller
{
    protected AttributeSetService $attributeSetService;
    
    /**
     * Get vendor by UUID
     */
    protected function getVendor(string $vendorUuid): Vendor
    {
        return Vendor::where('uuid', $vendorUuid)->firstOrFail();
    }
    
    /**
     * Get vendor ID by UUID
     */
    protected function getVendorId(string $vendorUuid): int
    {
        return $this->getVendor($vendorUuid)->id;
    }
    
    /**
     * Get the attribute set service instance for the vendor
     */
    protected function getAttributeSetService(string $vendorUuid): AttributeSetService
    {
        $vendor = $this->getVendor($vendorUuid);
        $magentoService = MagentoService::forVendor($vendor);
        
        return new AttributeSetService($magentoService);
    }
    
    /**
     * List all attribute sets for a vendor
     */
    public function index(Request $request, string $vendorUuid)
    {
        $validator = Validator::make($request->all(), [
            'sync_status' => 'nullable|in:pending,synced,failed,local_only',
            'is_active' => 'nullable|boolean',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
            'sync_from_magento' => 'nullable|boolean',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        // If sync from Magento is requested, fetch fresh data
        if ($request->boolean('sync_from_magento')) {
            try {
                $service = $this->getAttributeSetService($vendorUuid);
                $syncResult = $service->bulkSyncFromMagentoToLocal($vendorUuid);
                
                return response()->json([
                    'success' => true,
                    'sync_result' => $syncResult,
                    'message' => "Synced {$syncResult['synced_count']} attribute sets from Magento",
                ], Response::HTTP_OK);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to sync from Magento: ' . $e->getMessage(),
                ], Response::HTTP_BAD_GATEWAY);
            }
        }
        
        // Get vendor ID
        $vendorId = $this->getVendorId($vendorUuid);
        
        // Query local records
        $query = MagentoAttributeSet::where('vendor_id', $vendorId);
        
        if ($request->has('sync_status')) {
            $query->where('sync_status', $request->sync_status);
        }
        
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('attribute_set_name', 'like', '%' . $search . '%')
                  ->orWhere('local_display_name', 'like', '%' . $search . '%');
            });
        }
        
        // Apply sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);
        
        $perPage = $request->get('per_page', 15);
        $attributeSets = $query->paginate($perPage);
        
        // Add attribute count
        $attributeSets->getCollection()->transform(function ($attributeSet) {
            $attributeSet->attribute_count = count($attributeSet->assigned_attribute_ids ?? []);
            return $attributeSet;
        });
        
        return response()->json([
            'success' => true,
            'data' => $attributeSets->items(),
            'meta' => [
                'current_page' => $attributeSets->currentPage(),
                'per_page' => $attributeSets->perPage(),
                'total' => $attributeSets->total(),
                'last_page' => $attributeSets->lastPage(),
            ],
        ], Response::HTTP_OK);
    }
    
    /**
     * Store a new attribute set locally and optionally sync to Magento
     */
    public function store(Request $request, string $vendorUuid)
    {
        $validator = Validator::make($request->all(), [
            'attribute_set_name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'entity_type_id' => 'nullable|integer|in:4', // Currently only supporting catalog_product
            'description' => 'nullable|string',
            'assigned_attribute_ids' => 'nullable|array',
            'assigned_attribute_ids.*' => 'integer',
            'attribute_group_data' => 'nullable|array',
            'sync_to_magento' => 'boolean',
            'is_active' => 'boolean',
            'local_display_name' => 'nullable|string|max:255',
            'local_notes' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        // Get vendor
        $vendor = $this->getVendor($vendorUuid);
        
        // Create local record
        $attributeSet = MagentoAttributeSet::create([
            'vendor_id' => $vendor->id,
            'attribute_set_name' => $request->attribute_set_name,
            'sort_order' => $request->sort_order ?? 0,
            'entity_type_id' => $request->entity_type_id ?? 4,
            'description' => $request->description,
            'assigned_attribute_ids' => $request->assigned_attribute_ids,
            'attribute_group_data' => $request->attribute_group_data,
            'sync_status' => $request->boolean('sync_to_magento') ? 'pending' : 'local_only',
            'is_active' => $request->is_active ?? true,
            'local_display_name' => $request->local_display_name,
            'local_notes' => $request->local_notes,
        ]);
        
        // Sync to Magento if requested
        if ($request->boolean('sync_to_magento')) {
            try {
                $service = $this->getAttributeSetService($vendorUuid);
                $syncResult = $service->pushLocalToMagento($attributeSet);
                
                if ($syncResult['success']) {
                    $attributeSet->refresh();
                    return response()->json([
                        'success' => true,
                        'data' => $attributeSet,
                        'sync_result' => $syncResult,
                        'message' => 'Attribute set created and synced to Magento successfully.',
                    ], Response::HTTP_CREATED);
                } else {
                    return response()->json([
                        'success' => false,
                        'data' => $attributeSet,
                        'sync_error' => $syncResult['error'],
                        'message' => 'Attribute set created locally but failed to sync to Magento.',
                    ], Response::HTTP_CREATED);
                }
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'data' => $attributeSet,
                    'sync_error' => $e->getMessage(),
                    'message' => 'Attribute set created locally but sync to Magento failed.',
                ], Response::HTTP_CREATED);
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $attributeSet,
            'message' => 'Attribute set created locally successfully.',
        ], Response::HTTP_CREATED);
    }
    
    /**
     * Show specific attribute set
     */
    public function show(Request $request, string $vendorUuid, string $id)
    {
        $validator = Validator::make($request->all(), [
            'include_magento_details' => 'nullable|boolean',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        $vendorId = $this->getVendorId($vendorUuid);
        
        $attributeSet = MagentoAttributeSet::where('vendor_id', $vendorId)
            ->where('uuid', $id)
            ->withTrashed()
            ->firstOrFail();
        
        // Add attribute count
        $attributeSet->attribute_count = count($attributeSet->assigned_attribute_ids ?? []);
        
        $response = [
            'success' => true,
            'data' => $attributeSet,
        ];
        
        // Include Magento details if requested
        if ($request->boolean('include_magento_details') && $attributeSet->magento_attr_set_id) {
            try {
                $service = $this->getAttributeSetService($vendorUuid);
                $details = $service->getFullAttributeSetDetails($attributeSet->magento_attr_set_id);
                $response['magento_details'] = $details;
            } catch (\Exception $e) {
                $response['magento_details_error'] = $e->getMessage();
            }
        }
        
        return response()->json($response, Response::HTTP_OK);
    }
    
    /**
     * Update attribute set
     */
    public function update(Request $request, string $vendorUuid, string $id)
    {
        $validator = Validator::make($request->all(), [
            'attribute_set_name' => 'sometimes|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'assigned_attribute_ids' => 'nullable|array',
            'assigned_attribute_ids.*' => 'integer',
            'attribute_group_data' => 'nullable|array',
            'sync_to_magento' => 'boolean',
            'is_active' => 'boolean',
            'local_display_name' => 'nullable|string|max:255',
            'local_notes' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        $vendorId = $this->getVendorId($vendorUuid);
        
        $attributeSet = MagentoAttributeSet::where('vendor_id', $vendorId)
            ->where('uuid', $id)
            ->firstOrFail();
        
        // Update local record
        $attributeSet->update($request->only([
            'attribute_set_name',
            'sort_order',
            'description',
            'assigned_attribute_ids',
            'attribute_group_data',
            'is_active',
            'local_display_name',
            'local_notes',
        ]));
        
        // Sync to Magento if requested
        if ($request->boolean('sync_to_magento') && $attributeSet->magento_attr_set_id) {
            try {
                $service = $this->getAttributeSetService($vendorUuid);
                $syncResult = $service->pushLocalToMagento($attributeSet);
                
                if (!$syncResult['success']) {
                    return response()->json([
                        'success' => true,
                        'data' => $attributeSet,
                        'sync_error' => $syncResult['error'],
                        'message' => 'Attribute set updated locally but failed to sync to Magento.',
                    ], Response::HTTP_OK);
                }
            } catch (\Exception $e) {
                return response()->json([
                    'success' => true,
                    'data' => $attributeSet,
                    'sync_error' => $e->getMessage(),
                    'message' => 'Attribute set updated locally but sync to Magento failed.',
                ], Response::HTTP_OK);
            }
        }
        
        return response()->json([
            'success' => true,
            'data' => $attributeSet,
            'message' => 'Attribute set updated successfully.',
        ], Response::HTTP_OK);
    }
    
    /**
     * Delete attribute set
     */
    public function destroy(Request $request, string $vendorUuid, string $id)
    {
        $validator = Validator::make($request->all(), [
            'delete_from_magento' => 'boolean',
            'force_delete' => 'boolean',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        $vendorId = $this->getVendorId($vendorUuid);
        
        $attributeSet = MagentoAttributeSet::where('vendor_id', $vendorId)
            ->where('uuid', $id)
            ->firstOrFail();
        
        // Delete from Magento if requested
        if ($request->boolean('delete_from_magento') && $attributeSet->magento_attr_set_id) {
            try {
                $service = $this->getAttributeSetService($vendorUuid);
                $service->deleteAttributeSet($attributeSet->magento_attr_set_id);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to delete from Magento: ' . $e->getMessage(),
                ], Response::HTTP_BAD_GATEWAY);
            }
        }
        
        // Delete from local
        if ($request->boolean('force_delete')) {
            $attributeSet->forceDelete();
            $message = 'Attribute set permanently deleted.';
        } else {
            $attributeSet->delete();
            $message = 'Attribute set soft deleted.';
        }
        
        return response()->json([
            'success' => true,
            'message' => $message,
        ], Response::HTTP_OK);
    }
    
    /**
     * Sync single attribute set from Magento to local
     */
    public function syncFromMagento(Request $request, string $vendorUuid, int $magentoAttrSetId)
    {
        $validator = Validator::make($request->all(), [
            'include_attributes' => 'nullable|boolean',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        try {
            $service = $this->getAttributeSetService($vendorUuid);
            $attributeSet = $service->syncFromMagentoToLocal($vendorUuid, $magentoAttrSetId);
            
            $response = [
                'success' => true,
                'data' => $attributeSet,
                'message' => 'Attribute set synced from Magento successfully.',
            ];
            
            // Include attributes if requested
            if ($request->boolean('include_attributes')) {
                $attributes = $service->getAttributeSetAttributes($magentoAttrSetId);
                $response['attributes'] = $attributes;
            }
            
            return response()->json($response, Response::HTTP_OK);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Sync failed: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
    
    /**
     * Get attributes for an attribute set
     */
    public function getAttributes(string $vendorUuid, string $id)
    {
        $vendorId = $this->getVendorId($vendorUuid);
        
        $attributeSet = MagentoAttributeSet::where('vendor_id', $vendorId)
            ->where('uuid', $id)
            ->firstOrFail();
        
        if (!$attributeSet->magento_attr_set_id) {
            return response()->json([
                'success' => false,
                'error' => 'Attribute set not synced with Magento yet.',
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            $service = $this->getAttributeSetService($vendorUuid);
            $attributes = $service->getAttributeSetAttributes($attributeSet->magento_attr_set_id);
            
            return response()->json([
                'success' => true,
                'data' => $attributes,
                'attribute_set' => $attributeSet,
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
    
    /**
     * Get attribute groups for an attribute set
     */
    public function getGroups(string $vendorUuid, string $id)
    {
        $vendorId = $this->getVendorId($vendorUuid);
        
        $attributeSet = MagentoAttributeSet::where('vendor_id', $vendorId)
            ->where('uuid', $id)
            ->firstOrFail();
        
        if (!$attributeSet->magento_attr_set_id) {
            return response()->json([
                'success' => false,
                'error' => 'Attribute set not synced with Magento yet.',
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            $service = $this->getAttributeSetService($vendorUuid);
            $groups = $service->getAttributeGroups($attributeSet->magento_attr_set_id);
            
            return response()->json([
                'success' => true,
                'data' => $groups,
                'attribute_set' => $attributeSet,
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
    
    /**
     * Assign attribute to attribute set
     */
    public function assignAttribute(Request $request, string $vendorUuid, string $id)
    {
        $validator = Validator::make($request->all(), [
            'attribute_id' => 'required|integer',
            'attribute_group_id' => 'required|integer',
            'sort_order' => 'nullable|integer|min:0',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        
        $vendorId = $this->getVendorId($vendorUuid);
        
        $attributeSet = MagentoAttributeSet::where('vendor_id', $vendorId)
            ->where('uuid', $id)
            ->firstOrFail();
        
        if (!$attributeSet->magento_attr_set_id) {
            return response()->json([
                'success' => false,
                'error' => 'Attribute set not synced with Magento yet.',
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            $service = $this->getAttributeSetService($vendorUuid);
            $result = $service->assignAttributeToSet(
                $attributeSet->magento_attr_set_id,
                $request->attribute_id,
                $request->attribute_group_id,
                $request->sort_order ?? 0
            );
            
            // Update local assigned attributes
            $assignedAttributes = $attributeSet->assigned_attribute_ids ?? [];
            if (!in_array($request->attribute_id, $assignedAttributes)) {
                $assignedAttributes[] = $request->attribute_id;
                $attributeSet->update(['assigned_attribute_ids' => $assignedAttributes]);
            }
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Attribute assigned successfully.',
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
    
    /**
     * Remove attribute from attribute set
     */
    public function removeAttribute(Request $request, string $vendorUuid, string $id, int $attributeId)
    {
        $vendorId = $this->getVendorId($vendorUuid);
        
        $attributeSet = MagentoAttributeSet::where('vendor_id', $vendorId)
            ->where('uuid', $id)
            ->firstOrFail();
        
        if (!$attributeSet->magento_attr_set_id) {
            return response()->json([
                'success' => false,
                'error' => 'Attribute set not synced with Magento yet.',
            ], Response::HTTP_BAD_REQUEST);
        }
        
        try {
            $service = $this->getAttributeSetService($vendorUuid);
            $result = $service->removeAttributeFromSet($attributeSet->magento_attr_set_id, $attributeId);
            
            // Update local assigned attributes
            $assignedAttributes = $attributeSet->assigned_attribute_ids ?? [];
            $assignedAttributes = array_values(array_diff($assignedAttributes, [$attributeId]));
            $attributeSet->update(['assigned_attribute_ids' => $assignedAttributes]);
            
            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Attribute removed successfully.',
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
    
    /**
     * Bulk sync attribute sets from Magento
     */
    public function bulkSync(string $vendorUuid)
    {
        try {
            $service = $this->getAttributeSetService($vendorUuid);
            $result = $service->bulkSyncFromMagentoToLocal($vendorUuid);
            
            return response()->json([
                'success' => true,
                'message' => "Synced {$result['synced_count']} attribute sets from Magento",
                'synced_count' => $result['synced_count'],
                'errors' => $result['errors'],
            ], Response::HTTP_OK);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Bulk sync failed: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
    
    /**
     * Push local attribute set to Magento
     */
    public function pushToMagento(string $vendorUuid, string $id)
    {
        $vendorId = $this->getVendorId($vendorUuid);
        
        $attributeSet = MagentoAttributeSet::where('vendor_id', $vendorId)
            ->where('uuid', $id)
            ->firstOrFail();
        
        try {
            $service = $this->getAttributeSetService($vendorUuid);
            $result = $service->pushLocalToMagento($attributeSet);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result,
                    'message' => "Attribute set {$result['action']} in Magento successfully.",
                ], Response::HTTP_OK);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'],
                    'message' => 'Failed to push attribute set to Magento.',
                ], Response::HTTP_BAD_GATEWAY);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
}