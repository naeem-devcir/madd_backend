<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttributeGroupMapping;
use App\Models\Vendor\Vendor;
use App\Models\AttributeSets;
use App\Services\AttributeSetService;
use App\Services\Integration\MagentoService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
        $query = AttributeSets::where('vendor_id', $vendorId);

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
     * Store a new attribute set - ONLY create if Magento sync succeeds
     */
    // public function store(Request $request, string $vendorUuid)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'attribute_set_name' => 'required|string|max:255',
    //         'sort_order' => 'nullable|integer|min:0',
    //         'entity_type_id' => 'nullable|integer|in:4',
    //         'skeleton_id' => 'nullable|integer|min:1', // Based on existing set
    //         'description' => 'nullable|string',
    //         'local_display_name' => 'nullable|string|max:255',
    //         'local_notes' => 'nullable|string',
    //         'is_active' => 'boolean',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'success' => false,
    //             'errors' => $validator->errors(),
    //         ], Response::HTTP_UNPROCESSABLE_ENTITY);
    //     }

    //     try {
    //         $vendor = $this->getVendor($vendorUuid);
    //         $service = $this->getAttributeSetService($vendorUuid);

    //         // Get skeleton_id (based on existing attribute set)
    //         // Default to 4 (Default attribute set) if not provided
    //         $skeletonId = $request->skeleton_id ?? 4;

    //         // First, try to create in Magento
    //         $createData = [
    //             'attribute_set_name' => $request->attribute_set_name,
    //             'sort_order' => (int) ($request->sort_order ?? 0),
    //             'entity_type_id' => (int) ($request->entity_type_id ?? 4),
    //             'skeleton_id' => (int) $skeletonId, // Make sure it's integer
    //         ];

    //         $magentoResult = $service->createAttributeSet($createData);

    //         $magentoId = $magentoResult['attribute_set_id'] ?? null;

    //         if (!$magentoId) {
    //             return response()->json([
    //                 'success' => false,
    //                 'error' => 'Failed to create attribute set in Magento: No ID returned',
    //                 'magento_response' => $magentoResult,
    //             ], Response::HTTP_BAD_GATEWAY);
    //         }

    //         // ONLY create local record after Magento success
    //         $attributeSet = AttributeSets::create([
    //             'uuid' => (string) Str::uuid(),
    //             'vendor_id' => $vendor->id,
    //             'magento_attr_set_id' => $magentoId,
    //             'attribute_set_name' => $request->attribute_set_name,
    //             'sort_order' => $request->sort_order ?? 0,
    //             'entity_type_id' => $request->entity_type_id ?? 4,
    //             'magento_entity_type_code' => 'catalog_product',
    //             'description' => $request->description,
    //             'sync_status' => 'synced',
    //             'is_active' => $request->boolean('is_active', true),
    //             'local_display_name' => $request->local_display_name,
    //             'local_notes' => $request->local_notes,
    //             'last_synced_at' => now(),
    //         ]);

    //         return response()->json([
    //             'success' => true,
    //             'data' => $attributeSet,
    //             'magento_data' => $magentoResult,
    //             'message' => 'Attribute set created successfully in Magento and locally.',
    //         ], Response::HTTP_CREATED);
    //     } catch (\Exception $e) {
    //         // DO NOT create local record on failure
    //         return response()->json([
    //             'success' => false,
    //             'error' => 'Failed to create attribute set in Magento: ' . $e->getMessage(),
    //         ], Response::HTTP_BAD_GATEWAY);
    //     }
    // }

    public function store(Request $request, string $vendorUuid)
    {
        $validator = Validator::make($request->all(), [
            'attribute_set_name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'entity_type_id' => 'nullable|integer|in:4',
            'skeleton_id' => 'nullable|integer|min:1',
            'description' => 'nullable|string',
            'local_display_name' => 'nullable|string|max:255',
            'local_notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $vendor = $this->getVendor($vendorUuid);
            $service = $this->getAttributeSetService($vendorUuid);

            $skeletonId = $request->skeleton_id ?? 4;

            // Create in Magento
            $createData = [
                'attribute_set_name' => $request->attribute_set_name,
                'sort_order' => (int) ($request->sort_order ?? 0),
                'entity_type_id' => (int) ($request->entity_type_id ?? 4),
                'skeleton_id' => (int) $skeletonId,
            ];

            $magentoResult = $service->createAttributeSet($createData);
            $magentoId = $magentoResult['attribute_set_id'] ?? null;

            if (!$magentoId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to create attribute set in Magento',
                ], Response::HTTP_BAD_GATEWAY);
            }

            // Create local attribute set record
            $attributeSet = AttributeSets::create([
                'uuid' => (string) Str::uuid(),
                'vendor_id' => $vendor->id,
                'magento_attr_set_id' => $magentoId,
                'attribute_set_name' => $request->attribute_set_name,
                'sort_order' => $request->sort_order ?? 0,
                'entity_type_id' => $request->entity_type_id ?? 4,
                'magento_entity_type_code' => 'catalog_product',
                'description' => $request->description,
                'sync_status' => 'synced',
                'is_active' => $request->boolean('is_active', true),
                'local_display_name' => $request->local_display_name,
                'local_notes' => $request->local_notes,
                'last_synced_at' => now(),
            ]);

            // Get the skeleton attribute set to copy mappings
            $skeletonAttributeSet = AttributeSets::where('magento_attr_set_id', $skeletonId)->first();

            if ($skeletonAttributeSet) {
                // Copy all mappings from skeleton to new attribute set
                $skeletonMappings = AttributeGroupMapping::where('attribute_set_id', $skeletonAttributeSet->id)->get();

                foreach ($skeletonMappings as $mapping) {
                    AttributeGroupMapping::create([
                        'vendor_id' => $vendor->id,
                        'attribute_set_id' => $attributeSet->id,
                        'attribute_group_id' => $mapping->attribute_group_id,
                        'attribute_id' => $mapping->attribute_id,
                        'sort_order' => $mapping->sort_order,
                        'attribute_code' => $mapping->attribute_code,
                        'frontend_label' => $mapping->frontend_label,
                        'is_system' => $mapping->is_system,
                        'is_required' => $mapping->is_required,
                    ]);
                }

                // Also copy the attribute_group_data (groups structure)
                $attributeSet->update([
                    'attribute_group_data' => $skeletonAttributeSet->attribute_group_data,
                    'assigned_attribute_ids' => $skeletonAttributeSet->assigned_attribute_ids,
                ]);
            } else {
                // If skeleton not found in local DB, fetch from Magento
                $this->syncAttributeSetGroupsAndMappings($attributeSet, $magentoId, $service);
            }

            return response()->json([
                'success' => true,
                'data' => $attributeSet,
                'message' => 'Attribute set created successfully.',
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create attribute set: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    // Helper method to sync groups and mappings from Magento
    private function syncAttributeSetGroupsAndMappings($attributeSet, $magentoId, $service)
    {
        // Fetch groups from Magento
        $groups = $service->getAttributeGroups($magentoId);
        $attributeSet->update(['attribute_group_data' => $groups]);

        // Fetch assigned attributes from Magento
        $assignedAttributes = $service->getAttributeSetAttributes($magentoId);
        $attributeIds = collect($assignedAttributes)->pluck('attribute_id')->toArray();
        $attributeSet->update(['assigned_attribute_ids' => $attributeIds]);

        // Create mappings for each assigned attribute
        $vendor = $attributeSet->vendor;
        foreach ($assignedAttributes as $attribute) {
            AttributeGroupMapping::updateOrCreate(
                [
                    'vendor_id' => $vendor->id,
                    'attribute_set_id' => $attributeSet->id,
                    'attribute_id' => $attribute['attribute_id'],
                ],
                [
                    'attribute_group_id' => $attribute['attribute_group_id'] ?? 0,
                    'sort_order' => $attribute['sort_order'] ?? 0,
                    'attribute_code' => $attribute['attribute_code'],
                    'frontend_label' => $attribute['default_frontend_label'] ?? $attribute['attribute_code'],
                    'is_system' => !($attribute['is_user_defined'] ?? true),
                    'is_required' => $attribute['is_required'] ?? false,
                ]
            );
        }
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

        $attributeSet = AttributeSets::where('vendor_id', $vendorId)
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

        $attributeSet = AttributeSets::where('vendor_id', $vendorId)
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

        $attributeSet = AttributeSets::where('vendor_id', $vendorId)
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

        $attributeSet = AttributeSets::where('vendor_id', $vendorId)
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

        $attributeSet = AttributeSets::where('vendor_id', $vendorId)
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
        $vendor = $this->getVendor($vendorUuid);

        $attributeSet = AttributeSets::where('vendor_id', $vendorId)
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

            // Call Magento API to assign attribute FIRST
            $result = $service->assignAttributeToSet(
                $attributeSet->magento_attr_set_id,
                $request->attribute_id,
                $request->attribute_group_id,
                $request->sort_order ?? 0
            );

            // ONLY IF Magento succeeds, then save to local database
            if ($result) {
                // Get attribute details from Magento
                $attributeDetails = $service->getAttribute($request->attribute_id);

                // Save the mapping to local database
                AttributeGroupMapping::updateOrCreate(
                    [
                        'vendor_id' => $vendor->id,
                        'attribute_set_id' => $attributeSet->id,
                        'attribute_id' => $request->attribute_id,
                    ],
                    [
                        'attribute_group_id' => $request->attribute_group_id,
                        'sort_order' => $request->sort_order ?? 0,
                        'attribute_code' => $attributeDetails['attribute_code'] ?? null,
                        'frontend_label' => $attributeDetails['default_frontend_label'] ?? $attributeDetails['frontend_label'] ?? null,
                        'is_user_defined' => $attributeDetails['is_user_defined'] ?? false,
                        'is_required' => $attributeDetails['is_required'] ?? false,
                    ]
                );

                // Update local assigned_attribute_ids (legacy)
                $assignedAttributes = $attributeSet->assigned_attribute_ids ?? [];
                if (!in_array($request->attribute_id, $assignedAttributes)) {
                    $assignedAttributes[] = $request->attribute_id;
                    $attributeSet->update(['assigned_attribute_ids' => $assignedAttributes]);
                }

                return response()->json([
                    'success' => true,
                    'data' => $result,
                    'message' => 'Attribute assigned successfully to Magento and local database.',
                ], Response::HTTP_OK);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'Magento API did not return a successful response.',
                ], Response::HTTP_BAD_GATEWAY);
            }
        } catch (\Exception $e) {
            // On Magento error, DO NOT save to local database
            return response()->json([
                'success' => false,
                'error' => 'Failed to assign attribute in Magento: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Remove attribute from attribute set
     */

    public function removeAttribute(Request $request, string $vendorUuid, string $id, int $attributeId)
    {
        $vendorId = $this->getVendorId($vendorUuid);
        $vendor = $this->getVendor($vendorUuid);

        $attributeSet = AttributeSets::where('vendor_id', $vendorId)
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

            // First, check if this is a system attribute
            $attributeDetails = $service->getAttribute($attributeId);

            if (isset($attributeDetails['is_user_defined']) && $attributeDetails['is_user_defined'] === false) {
                return response()->json([
                    'success' => false,
                    'error' => 'System attributes cannot be unassigned from Magento.',
                    'attribute_id' => $attributeId,
                    'attribute_code' => $attributeDetails['attribute_code'] ?? 'unknown',
                ], Response::HTTP_BAD_REQUEST);
            }

            // Call Magento API to unassign attribute (only for custom attributes)
            $result = $service->removeAttributeFromSet($attributeSet->magento_attr_set_id, $attributeId);

            // ONLY IF Magento succeeds, then remove from local database
            if ($result) {
                // Remove from attribute_group_mappings table
                AttributeGroupMapping::where('vendor_id', $vendor->id)
                    ->where('attribute_set_id', $attributeSet->id)
                    ->where('attribute_id', $attributeId)
                    ->delete();

                // Remove from assigned_attribute_ids array in attribute_sets table
                $assignedAttributes = $attributeSet->assigned_attribute_ids ?? [];
                $assignedAttributes = array_values(array_diff($assignedAttributes, [$attributeId]));
                $attributeSet->update(['assigned_attribute_ids' => $assignedAttributes]);

                return response()->json([
                    'success' => true,
                    'message' => 'Attribute unassigned successfully from Magento and local database.',
                    'attribute_id' => $attributeId,
                ], Response::HTTP_OK);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => 'Magento API did not return a successful response.',
                ], Response::HTTP_BAD_GATEWAY);
            }
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Check if it's a system attribute error from Magento
            if (strpos($errorMessage, 'system attribute') !== false || strpos($errorMessage, 'can\'t be deleted') !== false) {
                return response()->json([
                    'success' => false,
                    'error' => 'System attributes cannot be unassigned from Magento.',
                    'attribute_id' => $attributeId,
                    'magento_error' => $errorMessage,
                ], Response::HTTP_BAD_REQUEST);
            }

            // For any other error, don't modify local DB
            return response()->json([
                'success' => false,
                'error' => 'Failed to unassign attribute from Magento: ' . $errorMessage,
                'attribute_id' => $attributeId,
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

        $attributeSet = AttributeSets::where('vendor_id', $vendorId)
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


    /**
     * Get complete attribute set structure with groups and attributes
     */

    public function getStructure(string $vendorUuid, string $id)
    {
        try {
            $vendorId = $this->getVendorId($vendorUuid);
            $vendor = $this->getVendor($vendorUuid);

            $attributeSet = AttributeSets::where('vendor_id', $vendorId)
                ->where('uuid', $id)
                ->firstOrFail();

            if (!$attributeSet->magento_attr_set_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'Attribute set not synced with Magento yet.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $service = $this->getAttributeSetService($vendorUuid);
            $magentoAttrSetId = $attributeSet->magento_attr_set_id;

            // Get groups from attribute set data
            $groups = $attributeSet->attribute_group_data ?? [];

            // Get attribute-group mappings from local database
            $mappings = AttributeGroupMapping::where('vendor_id', $vendor->id)
                ->where('attribute_set_id', $attributeSet->id)
                ->get()
                ->keyBy('attribute_id');

            // Get ALL product attributes from Magento
            $allAttributesResponse = $service->getAllProductAttributes();
            $allAttributesItems = $allAttributesResponse['items'] ?? [];

            // Create a quick lookup map
            $attributeLookup = [];
            foreach ($allAttributesItems as $attr) {
                $attributeLookup[$attr['attribute_id']] = $attr;
            }

            // Get ACTUALLY assigned attributes from Magento (not just from mappings)
            $assignedInMagento = $service->getAttributeSetAttributes($magentoAttrSetId);
            $assignedAttributeIds = collect($assignedInMagento)->pluck('attribute_id')->toArray();

            // Build groups with their specific attributes from mappings
            $formattedGroups = [];
            foreach ($groups as $group) {
                $groupId = (int) $group['attribute_group_id'];
                $groupAttributes = [];

                foreach ($mappings as $attributeId => $mapping) {
                    if ($mapping->attribute_group_id == $groupId) {
                        $attributeDetail = $attributeLookup[$attributeId] ?? null;

                        $groupAttributes[] = [
                            'attribute_id' => $attributeId,
                            'attribute_code' => $attributeDetail['attribute_code'] ?? $mapping->attribute_code,
                            'frontend_label' => $attributeDetail['default_frontend_label'] ?? $mapping->frontend_label,
                            'sort_order' => $mapping->sort_order,
                            'is_system' => !($attributeDetail['is_user_defined'] ?? true),
                            'is_required' => $attributeDetail['is_required'] ?? false,
                        ];
                    }
                }

                // Sort attributes by sort_order
                usort($groupAttributes, function ($a, $b) {
                    return $a['sort_order'] <=> $b['sort_order'];
                });

                $formattedGroups[] = [
                    'attribute_group_id' => $groupId,
                    'attribute_group_name' => $group['attribute_group_name'],
                    'sort_order' => $group['sort_order'] ?? 0,
                    'attributes' => $groupAttributes,
                ];
            }

            // Build unassigned attributes - ONLY attributes NOT assigned to this set
            $unassignedAttributes = [];
            foreach ($allAttributesItems as $attr) {
                $attributeId = $attr['attribute_id'];

                // Only include if NOT in the assigned list from Magento
                if (!in_array($attributeId, $assignedAttributeIds)) {
                    // Optional: Filter out very old/internal attributes if needed
                    // Skip attributes that are not user visible
                    if (isset($attr['is_visible']) && $attr['is_visible'] === false) {
                        continue;
                    }

                    $unassignedAttributes[] = [
                        'attribute_id' => $attributeId,
                        'attribute_code' => $attr['attribute_code'],
                        'frontend_label' => $attr['default_frontend_label'] ?? $attr['attribute_code'],
                        'is_system' => !($attr['is_user_defined'] ?? true),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'attribute_set_id' => $magentoAttrSetId,
                    'attribute_set_name' => $attributeSet->attribute_set_name,
                    'groups' => $formattedGroups,
                    'unassigned_attributes' => $unassignedAttributes,
                ],
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            \Log::error('getStructure error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }
    }
}
