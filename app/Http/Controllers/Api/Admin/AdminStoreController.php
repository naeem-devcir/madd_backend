<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncStoresRequest;
use App\Models\Vendor\Vendor;
use App\Services\Store\StoreService;
use App\Models\Vendor\VendorStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminStoreController extends Controller
{
    protected ?StoreService $storeService = null;

    public function __construct()
    {
        // Initialize without vendor
        $this->storeService = new StoreService();
    }

    /**
     * Get all local stores (with optional relationships)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllLocalStores(Request $request): JsonResponse
    {
        try {
            $query = VendorStore::query()
                ->with(['vendor' => function ($q) {
                    $q->select('id', 'uuid', 'company_name', 'contact_email');
                }]);

            // Optional filtering by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Optional filtering by is_demo
            if ($request->has('is_demo')) {
                $query->where('is_demo', filter_var($request->is_demo, FILTER_VALIDATE_BOOLEAN));
            }

            // Order by
            $orderBy = $request->get('order_by', 'created_at');
            $orderDir = $request->get('order_dir', 'desc');
            $query->orderBy($orderBy, $orderDir);

            $stores = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Stores retrieved successfully',
                'data' => [
                    'total' => $stores->count(),
                    'stores' => $stores
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch local stores', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stores: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get local stores by vendor UUID
     * 
     * @param string $vendorUuid
     * @param Request $request
     * @return JsonResponse
     */
    public function getLocalStoresByVendor(string $vendorUuid, Request $request): JsonResponse
    {
        try {
            // Find vendor by UUID
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found',
                    'data' => null
                ], 404);
            }

            $query = VendorStore::where('vendor_id', $vendor->id)
                ->with(['vendor' => function ($q) {
                    $q->select('id', 'uuid', 'company_name', 'contact_email');
                }]);

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by active/activated
            if ($request->has('is_activated')) {
                if (filter_var($request->is_activated, FILTER_VALIDATE_BOOLEAN)) {
                    $query->whereNotNull('activated_at');
                } else {
                    $query->whereNull('activated_at');
                }
            }

            // Order by
            $orderBy = $request->get('order_by', 'created_at');
            $orderDir = $request->get('order_dir', 'desc');
            $query->orderBy($orderBy, $orderDir);

            $stores = $query->get();

            return response()->json([
                'success' => true,
                'message' => 'Stores retrieved successfully for vendor',
                'data' => [
                    'vendor' => [
                        'uuid' => $vendor->uuid,
                        'company_name' => $vendor->company_name,
                        'contact_email' => $vendor->contact_email
                    ],
                    'total_stores' => $stores->count(),
                    'active_stores' => $stores->where('status', 'active')->count(),
                    'max_stores_allowed' => 10, // You can set this based on vendor's plan
                    'stores' => $stores
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch stores by vendor', [
                'vendor_uuid' => $vendorUuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stores: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get single local store by UUID
     * 
     * @param string $uuid
     * @return JsonResponse
     */
    public function getLocalStoreByUuid(string $uuid): JsonResponse
    {
        try {
            $store = VendorStore::with(['vendor' => function ($q) {
                $q->select('id', 'uuid', 'company_name', 'contact_email', 'magento_base_url');
            }])
                ->where('uuid', $uuid)
                ->first();

            if (!$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store not found',
                    'data' => null
                ], 404);
            }

            // Decode metadata if needed
            if ($store->metadata && is_string($store->metadata)) {
                $store->metadata = json_decode($store->metadata, true);
            }

            // Decode JSON fields
            $jsonFields = ['seo_settings', 'payment_methods', 'shipping_methods', 'tax_settings', 'social_links'];
            foreach ($jsonFields as $field) {
                if ($store->$field && is_string($store->$field)) {
                    $store->$field = json_decode($store->$field, true);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Store retrieved successfully',
                'data' => $store
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch store by UUID', [
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch store: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get paginated local stores
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getPaginatedLocalStores(Request $request): JsonResponse
    {
        try {
            $perPage = $request->get('per_page', 15);
            $page = $request->get('page', 1);

            $query = VendorStore::query()
                ->with(['vendor' => function ($q) {
                    $q->select('id', 'uuid', 'company_name', 'contact_email');
                }]);

            // Apply filters
            if ($request->has('vendor_uuid')) {
                $vendor = Vendor::where('uuid', $request->vendor_uuid)->first();
                if ($vendor) {
                    $query->where('vendor_id', $vendor->id);
                }
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('store_name', 'like', "%{$search}%")
                        ->orWhere('store_slug', 'like', "%{$search}%")
                        ->orWhere('country_code', 'like', "%{$search}%")
                        ->orWhere('currency_code', 'like', "%{$search}%");
                });
            }

            // Date range filters
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }

            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $stores = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Stores retrieved successfully',
                'data' => [
                    'current_page' => $stores->currentPage(),
                    'per_page' => $stores->perPage(),
                    'total' => $stores->total(),
                    'last_page' => $stores->lastPage(),
                    'stores' => $stores->items()
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch paginated stores', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stores: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Filter local stores with advanced criteria
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function filterLocalStores(Request $request): JsonResponse
    {
        try {
            $query = VendorStore::query()
                ->with(['vendor' => function ($q) {
                    $q->select('id', 'uuid', 'company_name', 'contact_email');
                }]);

            // Multiple vendor filter
            if ($request->has('vendor_uuids') && is_array($request->vendor_uuids)) {
                $vendors = Vendor::whereIn('uuid', $request->vendor_uuids)->pluck('id');
                $query->whereIn('vendor_id', $vendors);
            }

            // Country filter
            if ($request->has('countries') && is_array($request->countries)) {
                $query->whereIn('country_code', $request->countries);
            }

            // Currency filter
            if ($request->has('currencies') && is_array($request->currencies)) {
                $query->whereIn('currency_code', $request->currencies);
            }

            // Language filter
            if ($request->has('languages') && is_array($request->languages)) {
                $query->whereIn('language_code', $request->languages);
            }

            // Magento store ID filter
            if ($request->has('magento_store_ids') && is_array($request->magento_store_ids)) {
                $query->whereIn('magento_store_id', $request->magento_store_ids);
            }

            // Status filter
            if ($request->has('statuses') && is_array($request->statuses)) {
                $query->whereIn('status', $request->statuses);
            }

            // Demo filter
            if ($request->has('is_demo')) {
                $query->where('is_demo', filter_var($request->is_demo, FILTER_VALIDATE_BOOLEAN));
            }

            // Activation filter
            if ($request->has('is_activated')) {
                if (filter_var($request->is_activated, FILTER_VALIDATE_BOOLEAN)) {
                    $query->whereNotNull('activated_at');
                } else {
                    $query->whereNull('activated_at');
                }
            }

            // Timezone filter
            if ($request->has('timezones') && is_array($request->timezones)) {
                $query->whereIn('timezone', $request->timezones);
            }

            // Get results
            $stores = $query->get();

            // Additional analytics
            $analytics = [
                'total_stores' => $stores->count(),
                'by_status' => $stores->groupBy('status')->map->count(),
                'by_country' => $stores->groupBy('country_code')->map->count(),
                'by_currency' => $stores->groupBy('currency_code')->map->count(),
                'demo_stores' => $stores->where('is_demo', true)->count(),
                'activated_stores' => $stores->whereNotNull('activated_at')->count()
            ];

            return response()->json([
                'success' => true,
                'message' => 'Filtered stores retrieved successfully',
                'data' => [
                    'filters_applied' => $request->all(),
                    'analytics' => $analytics,
                    'stores' => $stores
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to filter stores', [
                'error' => $e->getMessage(),
                'filters' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to filter stores: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Sync stores from Magento to local database
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function syncStores(Request $request): JsonResponse
    {
        try {
            // Validate request
            $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid'
            ]);

            $vendorUuid = $request->input('vendor_uuid');

            // Find vendor by UUID
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found',
                    'data' => null
                ], 404);
            }

            // Initialize store service for this vendor using the forVendor pattern
            $storeService = (new StoreService())->forVendor($vendor);

            // Perform sync
            $syncResult = $storeService->syncAllStores();

            // Prepare response message
            $message = sprintf(
                'Sync completed. Added: %d, Skipped: %d, Failed: %d',
                $syncResult['synced_count'],
                $syncResult['skipped_count'],
                $syncResult['failed_count']
            );

            $statusCode = $syncResult['failed_count'] > 0 ? 207 : 200;

            return response()->json([
                'success' => $syncResult['failed_count'] === 0,
                'message' => $message,
                'data' => [
                    'synced_count' => $syncResult['synced_count'],
                    'skipped_count' => $syncResult['skipped_count'],
                    'failed_count' => $syncResult['failed_count'],
                    'stores' => $syncResult['stores'],
                    'errors' => $syncResult['errors']
                ]
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Store sync API error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync stores: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get store by UUID using StoreService
     * 
     * @param string $vendorUuid
     * @param string $storeUuid
     * @return JsonResponse
     */
    public function getStoreByUuid(string $vendorUuid, string $storeUuid): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found',
                    'data' => null
                ], 404);
            }

            $storeService = (new StoreService())->forVendor($vendor);
            $store = $storeService->getStoreByUuid($storeUuid);

            if (!$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store not found',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Store retrieved successfully',
                'data' => $store
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch store by UUID via service', [
                'vendor_uuid' => $vendorUuid,
                'store_uuid' => $storeUuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch store: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Get store by Magento ID using StoreService
     * 
     * @param string $vendorUuid
     * @param int $magentoStoreId
     * @return JsonResponse
     */
    public function getStoreByMagentoId(string $vendorUuid, int $magentoStoreId): JsonResponse
    {
        try {
            $vendor = Vendor::where('uuid', $vendorUuid)->first();

            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found',
                    'data' => null
                ], 404);
            }

            $storeService = (new StoreService())->forVendor($vendor);
            $store = $storeService->getStoreByMagentoId($magentoStoreId);

            if (!$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store not found',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Store retrieved successfully',
                'data' => $store
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to fetch store by Magento ID via service', [
                'vendor_uuid' => $vendorUuid,
                'magento_store_id' => $magentoStoreId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch store: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
}