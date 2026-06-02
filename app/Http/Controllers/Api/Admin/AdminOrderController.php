<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Sync orders from Magento to local database
     * 
     * GET /api/admin/orders/sync
     * 
     * Required query parameter: vendor_uuid
     * Optional: store_uuid, page_size, max_pages, status, from_date, to_date
     */
    public function syncOrders(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'sometimes|string|exists:vendor_stores,uuid',
                'page_size' => 'sometimes|integer|min:1|max:100',
                'max_pages' => 'sometimes|integer|min:1|max:20',
                'status' => 'sometimes|string',
                'from_date' => 'sometimes|date',
                'to_date' => 'sometimes|date|after_or_equal:from_date',
            ]);

            $vendor = Vendor::where('uuid', $validated['vendor_uuid'])->firstOrFail();

            // If store_uuid is provided, verify it belongs to the vendor
            if (!empty($validated['store_uuid'])) {
                $store = VendorStore::where('uuid', $validated['store_uuid'])->firstOrFail();
                
                if ((int) $store->vendor_id !== (int) $vendor->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Store does not belong to the specified vendor',
                    ], 403);
                }
                
                if (empty($store->magento_store_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected store is not linked to a Magento store',
                    ], 422);
                }
            }

            $result = $this->orderService->syncOrdersFromMagento($vendor, [
                'store_uuid' => $validated['store_uuid'] ?? null,
                'magento_store_id' => $store->magento_store_id ?? null,
                'page_size' => $validated['page_size'] ?? 50,
                'max_pages' => $validated['max_pages'] ?? 5,
                'status' => $validated['status'] ?? null,
                'from_date' => $validated['from_date'] ?? null,
                'to_date' => $validated['to_date'] ?? null,
            ]);

            $statusCode = ($result['success'] ?? false) ? 200 : 500;

            return response()->json([
                'success' => $result['success'] ?? false,
                'message' => ($result['success'] ?? false) 
                    ? 'Orders synchronized successfully' 
                    : ($result['error'] ?? 'Order synchronization failed'),
                'data' => $result,
            ], $statusCode);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to sync orders', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync orders',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Create a manual order in Magento and sync to local database
     * 
     * POST /api/admin/orders/manual
     * 
     * Required body parameters: vendor_uuid, store_uuid, customer, items, addresses, payment_method, shipping_method
     */
    public function createManualOrder(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
                'customer.id' => 'required|integer|min:1',
                'customer.email' => 'required|email',
                'customer.group' => 'required|string|in:General,Retailer,Wholesale',
                'items' => 'required|array|min:1',
                'items.*.product_uuid' => 'required|string|exists:vendor_products,uuid',
                'items.*.sku' => 'required|string',
                'items.*.qty' => 'required|integer|min:1',
                'coupon_code' => 'nullable|string|max:100',
                'billing_address' => 'required|array',
                'billing_address.firstname' => 'required|string|max:100',
                'billing_address.lastname' => 'required|string|max:100',
                'billing_address.street' => 'required|string|max:500',
                'billing_address.country_id' => 'required|string|max:2',
                'billing_address.region' => 'required|string|max:100',
                'billing_address.city' => 'required|string|max:100',
                'billing_address.postcode' => 'required|string|max:30',
                'billing_address.telephone' => 'nullable|string|max:50',
                'shipping_address' => 'required|array',
                'shipping_address.firstname' => 'required|string|max:100',
                'shipping_address.lastname' => 'required|string|max:100',
                'shipping_address.street' => 'required|string|max:500',
                'shipping_address.country_id' => 'required|string|max:2',
                'shipping_address.region' => 'required|string|max:100',
                'shipping_address.city' => 'required|string|max:100',
                'shipping_address.postcode' => 'required|string|max:30',
                'shipping_address.telephone' => 'nullable|string|max:50',
                'payment_method' => 'required|string|max:100',
                'shipping_method.carrier_code' => 'required|string|max:100',
                'shipping_method.method_code' => 'required|string|max:100',
                'shipping_amount' => 'nullable|numeric|min:0',
                'history.comment' => 'nullable|string|max:1000',
                'history.append_comment' => 'sometimes|boolean',
                'history.email_confirmation' => 'sometimes|boolean',
                'totals' => 'nullable|array',
            ]);

            $vendor = Vendor::where('uuid', $validated['vendor_uuid'])->firstOrFail();
            $store = VendorStore::where('uuid', $validated['store_uuid'])->firstOrFail();

            // Verify store belongs to vendor
            if ((int) $store->vendor_id !== (int) $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }

            // Verify products belong to vendor/store
            $productUuids = collect($validated['items'])->pluck('product_uuid')->all();
            $validProductCount = \App\Models\Product\VendorProduct::whereIn('uuid', $productUuids)
                ->where('vendor_id', $vendor->id)
                ->where('vendor_store_id', $store->id)
                ->count();

            if ($validProductCount !== count(array_unique($productUuids))) {
                return response()->json([
                    'success' => false,
                    'message' => 'One or more products do not belong to the selected vendor/store',
                ], 422);
            }

            $result = $this->orderService->createManualOrderInMagento($vendor, $store, $validated, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Order created in Magento and synchronized locally',
                'data' => $result,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to create manual order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create order',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get orders from local database (read operation)
     * 
     * GET /api/admin/orders
     * 
     * Required query parameter: vendor_uuid
     * Optional: store_uuid, status, payment_status, search, date_from, date_to, amount_min, amount_max, page, per_page
     */
    public function index(Request $request)
    {
        try {
            $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'sometimes|string|exists:vendor_stores,uuid',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|in:pending,processing,shipped,delivered,cancelled,refunded',
                'payment_status' => 'sometimes|string|in:pending,paid,refunded,chargeback,failed',
                'search' => 'sometimes|string|min:2',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
                'amount_min' => 'sometimes|numeric|min:0',
                'amount_max' => 'sometimes|numeric|min:0',
            ]);

            $vendorUuid = $request->input('vendor_uuid');
            $storeUuid = $request->input('store_uuid');
            
            // If store_uuid is provided, verify it belongs to the vendor
            if ($storeUuid) {
                $vendor = Vendor::where('uuid', $vendorUuid)->first();
                $store = VendorStore::where('uuid', $storeUuid)->first();
                
                if ($vendor && $store && $store->vendor_id !== $vendor->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Store does not belong to the specified vendor',
                    ], 403);
                }
            }

            $filters = $request->only([
                'status', 'payment_status', 'search', 'date_from', 'date_to', 'amount_min', 'amount_max'
            ]);
            
            $perPage = $request->input('per_page', 15);
            
            $orders = $this->orderService->getOrdersFromDatabase(
                $vendorUuid,
                $storeUuid,
                $filters,
                $perPage
            );

            // Summary statistics
            $summary = $this->orderService->getOrderStatisticsFromDatabase(
                $vendorUuid,
                $storeUuid,
                $request->input('period', '30_days')
            );

            return response()->json([
                'success' => true,
                'data' => OrderResource::collection($orders),
                'summary' => $summary,
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
                'links' => [
                    'first' => $orders->url(1),
                    'last' => $orders->url($orders->lastPage()),
                    'prev' => $orders->previousPageUrl(),
                    'next' => $orders->nextPageUrl(),
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch orders', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get single order details from local database
     * 
     * GET /api/admin/orders/{orderId}
     * 
     * Required query parameter: vendor_uuid
     * Optional: store_uuid
     */
    public function show(Request $request, $id)
    {
        try {
            $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'sometimes|string|exists:vendor_stores,uuid',
            ]);

            $vendorUuid = $request->get('vendor_uuid');
            $storeUuid = $request->get('store_uuid');

            $order = $this->orderService->getOrderFromDatabase($id, $vendorUuid, $storeUuid);
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => new OrderResource($order),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch order', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}