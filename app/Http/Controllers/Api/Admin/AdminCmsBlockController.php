<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Models\CmsBlock;
use App\Services\Cms\CmsBlockService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AdminCmsBlockController extends Controller
{
    protected CmsBlockService $cmsBlockService;

    public function __construct(CmsBlockService $cmsBlockService)
    {
        $this->cmsBlockService = $cmsBlockService;
    }

    /**
     * Get all CMS blocks (READ from local DB)
     */
    public function index(Request $request, string $vendorUuid): JsonResponse
    {
        try {
            // $vendor = Vendor::find($vendorId);
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $filters = [
                'is_active' => $request->input('is_active'),
                'identifier' => $request->input('identifier'),
                'search' => $request->input('search'),
                'store_id' => $request->input('store_id'),
                'sort_by' => $request->input('sort_by', 'created_at'),
                'sort_order' => $request->input('sort_order', 'desc'),
                'per_page' => $request->input('per_page')
            ];

            $result = $this->cmsBlockService
                ->forVendor($vendor)
                ->getAllBlocks($filters);

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'vendor' => [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                ],
                'meta' => isset($result['current_page']) ? [
                    'current_page' => $result['current_page'],
                    'per_page' => $result['per_page'],
                    'total' => $result['total'],
                    'last_page' => $result['last_page']
                ] : null
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch CMS blocks',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get single CMS block (READ from local DB)
     */
    public function show(Request $request, string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            // $vendor = Vendor::find($vendorId);
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $block = $this->cmsBlockService
                ->forVendor($vendor)
                ->getBlockByUuid($uuid);

            if (!$block) {
                return response()->json([
                    'success' => false,
                    'message' => 'CMS Block not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $block
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch CMS block'
            ], 500);
        }
    }

    /**
     * Create CMS block (WRITE: Magento → Local)
     */
    public function store(Request $request, string $vendorUuid): JsonResponse
    {
        try {
            // $vendor = Vendor::find($vendorId);
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'identifier' => 'required|string|max:255|unique:cms_blocks,identifier',
                'title' => 'required|string|max:255',
                'content' => 'nullable|string',
                'is_active' => 'boolean',
                'store_ids' => 'nullable|array',
                'store_ids.*' => 'integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->cmsBlockService
                ->forVendor($vendor)
                ->createBlock($request->all());

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update CMS block (WRITE: Magento → Local)
     */
    public function update(Request $request, string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            // $vendor = Vendor::find($vendorId);
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'identifier' => 'sometimes|string|max:255|unique:cms_blocks,identifier,' . $uuid . ',uuid',
                'title' => 'sometimes|string|max:255',
                'content' => 'nullable|string',
                'is_active' => 'boolean',
                'store_ids' => 'nullable|array',
                'store_ids.*' => 'integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->cmsBlockService
                ->forVendor($vendor)
                ->updateBlock($uuid, $request->all());

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete CMS block (WRITE: Magento → Local)
     */
    public function destroy(string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            // $vendor = Vendor::find($vendorId);
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $result = $this->cmsBlockService
                ->forVendor($vendor)
                ->deleteBlock($uuid);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync all CMS blocks from Magento
     */
    public function sync(string $vendorUuid): JsonResponse
    {
        try {
            // $vendor = Vendor::find($vendorId);
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $result = $this->cmsBlockService
                ->forVendor($vendor)
                ->syncAllBlocks();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get block by identifier (READ from local DB)
     */
    public function byIdentifier(Request $request, string $vendorUuid, string $identifier): JsonResponse
    {
        try {
            // $vendor = Vendor::find($vendorId);
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();
            
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $block = $this->cmsBlockService
                ->forVendor($vendor)
                ->getBlockByIdentifier($identifier);

            if (!$block) {
                return response()->json([
                    'success' => false,
                    'message' => 'CMS Block not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $block
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch CMS block'
            ], 500);
        }
    }
}