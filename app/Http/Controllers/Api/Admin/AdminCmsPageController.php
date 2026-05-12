<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Services\Cms\CmsPageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AdminCmsPageController extends Controller
{
    protected CmsPageService $cmsPageService;

    public function __construct(CmsPageService $cmsPageService)
    {
        $this->cmsPageService = $cmsPageService;
    }

    /**
     * Get all CMS pages (READ from local DB)
     */
    public function index(Request $request, string $vendorUuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

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
                'sort_by' => $request->input('sort_by', 'sort_order'),
                'sort_order' => in_array($request->input('sort_order', 'asc'), ['asc', 'desc'])
                    ? $request->input('sort_order', 'asc')
                    : 'asc',
                'per_page' => $request->input('per_page')
            ];

            $result = $this->cmsPageService
                ->forVendor($vendor)
                ->getAllPages($filters);

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'vendor' => [
                    'uuid' => $vendor->uuid,
                    'name' => $vendor->legal_name,
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
                'message' => 'Failed to fetch CMS pages',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get single CMS page (READ from local DB)
     */
    public function show(Request $request, string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $page = $this->cmsPageService
                ->forVendor($vendor)
                ->getPageByUuid($uuid);

            if (!$page) {
                return response()->json([
                    'success' => false,
                    'message' => 'CMS Page not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $page
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch CMS page'
            ], 500);
        }
    }

    /**
     * Get page by identifier (READ from local DB)
     */
    public function byIdentifier(Request $request, string $vendorUuid, string $identifier): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $page = $this->cmsPageService
                ->forVendor($vendor)
                ->getPageByIdentifier($identifier);

            if (!$page) {
                return response()->json([
                    'success' => false,
                    'message' => 'CMS Page not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $page
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch CMS page'
            ], 500);
        }
    }

    /**
     * Create CMS page (WRITE: Magento → Local)
     */
    public function store(Request $request, string $vendorUuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'identifier' => 'required|string|max:255|unique:cms_pages,identifier',
                'title' => 'required|string|max:255',
                'content' => 'nullable|string',
                'page_layout' => 'nullable|string|max:255',
                'content_heading' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer',
                'meta_title' => 'nullable|string|max:255',
                'meta_keywords' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->cmsPageService
                ->forVendor($vendor)
                ->createPage($request->all());

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update CMS page (WRITE: Magento → Local)
     */
    public function update(Request $request, string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'identifier' => 'sometimes|string|max:255|unique:cms_pages,identifier,' . $uuid . ',uuid',
                'title' => 'sometimes|string|max:255',
                'content' => 'nullable|string',
                'page_layout' => 'nullable|string|max:255',
                'content_heading' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer',
                'meta_title' => 'nullable|string|max:255',
                'meta_keywords' => 'nullable|string|max:255',
                'meta_description' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $result = $this->cmsPageService
                ->forVendor($vendor)
                ->updatePage($uuid, $request->all());

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete CMS page (WRITE: Magento → Local)
     */
    public function destroy(string $vendorUuid, string $uuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $result = $this->cmsPageService
                ->forVendor($vendor)
                ->deletePage($uuid);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync all CMS pages from Magento
     */
    public function sync(string $vendorUuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found'
                ], 404);
            }

            $result = $this->cmsPageService
                ->forVendor($vendor)
                ->syncAllPages();

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
