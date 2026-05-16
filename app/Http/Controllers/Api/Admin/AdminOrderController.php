<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Models\Order\OrderStatusHistory;
use App\Models\Product\VendorProduct;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdminOrderController extends Controller
{
    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Get all orders with filters and pagination
     * Requires vendor_uuid or store_uuid as query parameters
     * Data fetched from local database only
     */
    public function index(Request $request)
    {
        try {
            $request->validate([
                'vendor_uuid' => 'required_without:store_uuid|string|exists:vendors,uuid',
                'store_uuid' => 'required_without:vendor_uuid|string|exists:vendor_stores,uuid',
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

            // Validate that at least one of vendor_uuid or store_uuid is provided
            if (!$request->has('vendor_uuid') && !$request->has('store_uuid')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either vendor_uuid or store_uuid is required',
                ], 422);
            }

            $filters = $request->only([
                'status', 'payment_status', 'search', 'date_from', 'date_to', 'amount_min', 'amount_max'
            ]);
            \Log::info("request came here 1");
            $vendorUuid = $request->input('vendor_uuid');
            $storeUuid = $request->input('store_uuid');
            $perPage = $request->input('per_page', 15);
            
            $orders = $this->orderService->getOrdersFromDatabase(
                $vendorUuid,
                $storeUuid,
                $filters,
                $perPage
            );

            // Summary statistics (using same filters)
            $summary = $this->orderService->getOrderStatisticsFromDatabase(
                $vendorUuid,
                $storeUuid,
                $request->input('period', '30_days')
            );

            return response()->json([
                'success' => true,
                'data' => OrderResource::collection($orders),
                'summary' => $summary,
                'filters_applied' => [
                    'vendor_uuid' => $vendorUuid,
                    'store_uuid' => $storeUuid,
                    'status' => $request->input('status'),
                    'date_from' => $request->input('date_from'),
                    'date_to' => $request->input('date_to'),
                ],
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
     * Create a Magento order manually for a selected vendor/store, then sync it locally.
     */
    public function store(Request $request)
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

            if ((int) $store->vendor_id !== (int) $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }

            $productUuids = collect($validated['items'])->pluck('product_uuid')->all();
            $validProductCount = VendorProduct::whereIn('uuid', $productUuids)
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
     * Get orders by store ID (using store_uuid as query param)
     * GET /orders/by-store/{storeId}?vendor_uuid=xxx
     */
    public function getOrdersByStore(Request $request, $storeId)
    {
        try {
            $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|in:pending,processing,shipped,delivered,cancelled,refunded',
            ]);

            // Find store by UUID or ID
            $store = VendorStore::where('uuid', $storeId)
                ->orWhere('id', $storeId)
                ->first();
                
            if (!$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store not found',
                ], 404);
            }

            // Verify store belongs to vendor
            $vendorUuid = $request->input('vendor_uuid');
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            
            if (!$vendor || $store->vendor_id !== $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }
            
            $filters = [];
            if ($request->has('status')) {
                $filters['status'] = $request->status;
            }
            
            $perPage = $request->input('per_page', 15);
            
            $orders = $this->orderService->getOrdersFromDatabase(
                $vendorUuid,
                $store->uuid,
                $filters,
                $perPage
            );
            
            return response()->json([
                'success' => true,
                'data' => OrderResource::collection($orders),
                'store_info' => [
                    'uuid' => $store->uuid,
                    'id' => $store->id,
                    'name' => $store->store_name,
                ],
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
            \Log::error('Failed to fetch orders by store', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders for this store',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get orders by vendor UUID
     * GET /orders/by-vendor/{vendorId}?vendor_uuid=xxx
     */
    public function getOrdersByVendor(Request $request, $vendorId)
    {
        try {
            $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|in:pending,processing,shipped,delivered,cancelled,refunded',
            ]);

            // Find vendor by UUID or ID
            $vendor = Vendor::where('uuid', $vendorId)
                ->orWhere('id', $vendorId)
                ->first();
                
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found',
                ], 404);
            }

            // Verify the vendor_uuid matches
            $requestedVendorUuid = $request->get('vendor_uuid');
            if ($vendor->uuid !== $requestedVendorUuid) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to vendor orders',
                ], 403);
            }
            
            $filters = [];
            if ($request->has('status')) {
                $filters['status'] = $request->status;
            }
            
            $perPage = $request->get('per_page', 15);
            
            $orders = $this->orderService->getOrdersFromDatabase(
                $vendor->uuid,
                null,
                $filters,
                $perPage
            );
            
            return response()->json([
                'success' => true,
                'data' => OrderResource::collection($orders),
                'vendor_info' => [
                    'uuid' => $vendor->uuid,
                    'id' => $vendor->id,
                    'name' => $vendor->company_name,
                ],
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
            \Log::error('Failed to fetch orders by vendor', [
                'vendor_id' => $vendorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders for this vendor',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get single order details
     * Requires vendor_uuid and store_uuid as query parameters
     */
    public function show(Request $request, $id)
    {
        try {
            $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
            ]);

            $vendorUuid = $request->get('vendor_uuid');
            $storeUuid = $request->get('store_uuid');
            
            // Verify store belongs to vendor
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            $store = VendorStore::where('uuid', $storeUuid)->first();
            
            if (!$vendor || !$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid vendor or store',
                ], 400);
            }
            
            if ($store->vendor_id !== $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }
            
            // Find order by UUID or ID with authorization
            $order = Order::where(function($query) use ($id) {
                    $query->where('uuid', $id)
                          ->orWhere('id', $id)
                          ->orWhere('magento_order_increment_id', $id);
                })
                ->where('vendor_id', $vendor->id)
                ->where('vendor_store_id', $store->id)
                ->with([
                    'vendor',
                    'vendorStore',
                    'customer',
                    'items',
                    'items.vendorProduct',
                    'statusHistory',
                    'tracking',
                    'tracking.carrier',
                    'paymentTransactions',
                    'refunds',
                    'settlement',
                ])
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found for this vendor and store',
                ], 404);
            }
            
            // Add timeline to response
            $timeline = $this->orderService->getOrderTimeline($order);

            return response()->json([
                'success' => true,
                'data' => (new OrderResource($order))->additional(['timeline' => $timeline]),
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

    /**
     * Get order timeline
     * Requires vendor_uuid and store_uuid as query parameters
     */
    public function timeline(Request $request, $orderUuid)
    {
        try {
            $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
            ]);

            $vendorUuid = $request->get('vendor_uuid');
            $storeUuid = $request->get('store_uuid');
            
            // Verify store belongs to vendor
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            $store = VendorStore::where('uuid', $storeUuid)->first();
            
            if (!$vendor || !$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid vendor or store',
                ], 400);
            }
            
            if ($store->vendor_id !== $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }
            
            $order = Order::where('uuid', $orderUuid)
                ->where('vendor_id', $vendor->id)
                ->where('vendor_store_id', $store->id)
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found for this vendor and store',
                ], 404);
            }
            
            $timeline = $this->orderService->getOrderTimeline($order);

            return response()->json([
                'success' => true,
                'data' => $timeline,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch order timeline', [
                'order_uuid' => $orderUuid,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order timeline',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update order status
     * Requires vendor_uuid and store_uuid in request body or query
     */
    public function updateStatus(Request $request, $id)
    {
        return $this->runMagentoOrderOperation($request, $id, [
            'status' => 'required|string|max:50',
            'notes' => 'nullable|string|max:1000',
            'comment' => 'nullable|string|max:1000',
        ], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->updateOrderStatusInMagento($vendor, $order, $store, $data, auth()->id())
        );

        try {
            $request->validate([
                'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
                'notes' => 'nullable|string',
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
            ]);

            $vendorUuid = $request->get('vendor_uuid');
            $storeUuid = $request->get('store_uuid');
            
            // Verify store belongs to vendor
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            $store = VendorStore::where('uuid', $storeUuid)->first();
            
            if (!$vendor || !$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid vendor or store',
                ], 400);
            }
            
            if ($store->vendor_id !== $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }
            
            $order = Order::where(function($query) use ($id) {
                    $query->where('uuid', $id)
                          ->orWhere('id', $id)
                          ->orWhere('magento_order_increment_id', $id);
                })
                ->where('vendor_id', $vendor->id)
                ->where('vendor_store_id', $store->id)
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found for this vendor and store',
                ], 404);
            }

            DB::beginTransaction();

            try {
                $oldStatus = $order->status;
                $order->status = $request->status;
                
                if ($request->status === 'shipped' && !$order->shipped_at) {
                    $order->shipped_at = now();
                }
                
                if ($request->status === 'delivered' && !$order->delivered_at) {
                    $order->delivered_at = now();
                }
                
                if ($request->status === 'processing' && !$order->processed_at) {
                    $order->processed_at = now();
                }
                
                $order->save();

                // Add to status history if you have the model
                // OrderStatusHistory::create([
                //     'order_id' => $order->id,
                //     'old_status' => $oldStatus,
                //     'new_status' => $request->status,
                //     'notes' => $request->notes,
                //     'user_id' => auth()->id(),
                // ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Order status updated successfully',
                    'data' => [
                        'order_uuid' => $order->uuid,
                        'order_id' => $order->id,
                        'status' => $order->status,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to update order status', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Cancel order
     * Requires vendor_uuid and store_uuid in request body or query
     */
    public function cancel(Request $request, $id)
    {
        return $this->runMagentoOrderOperation($request, $id, [
            'reason' => 'required|string|min:5|max:1000',
            'notes' => 'nullable|string|max:1000',
        ], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->cancelOrderInMagento($vendor, $order, $store, $data, auth()->id())
        );

        try {
            $request->validate([
                'reason' => 'required|string|min:5',
                'notes' => 'nullable|string',
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
            ]);

            $vendorUuid = $request->get('vendor_uuid');
            $storeUuid = $request->get('store_uuid');
            
            // Verify store belongs to vendor
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            $store = VendorStore::where('uuid', $storeUuid)->first();
            
            if (!$vendor || !$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid vendor or store',
                ], 400);
            }
            
            if ($store->vendor_id !== $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }
            
            $order = Order::where(function($query) use ($id) {
                    $query->where('uuid', $id)
                          ->orWhere('id', $id)
                          ->orWhere('magento_order_increment_id', $id);
                })
                ->where('vendor_id', $vendor->id)
                ->where('vendor_store_id', $store->id)
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found for this vendor and store',
                ], 404);
            }

            if (!$order->canBeCancelled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot be cancelled at this stage',
                ], 422);
            }

            DB::beginTransaction();

            try {
                $order->status = 'cancelled';
                $order->save();

                // Add cancellation note to admin_notes
                $adminNotes = $order->admin_note ? json_decode($order->admin_note, true) : [];
                $adminNotes[] = [
                    'type' => 'cancellation',
                    'reason' => $request->reason,
                    'notes' => $request->notes,
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => now()->toIso8601String(),
                ];
                $order->admin_note = json_encode($adminNotes);
                $order->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Order cancelled successfully',
                    'data' => [
                        'order_uuid' => $order->uuid,
                        'order_id' => $order->id,
                        'status' => $order->status,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to cancel order', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Process refund for order
     * Requires vendor_uuid and store_uuid in request body or query
     */
    public function processRefund(Request $request, $id)
    {
        return $this->runMagentoOrderOperation($request, $id, [
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|min:5|max:1000',
            'notes' => 'nullable|string|max:1000',
            'notify' => 'sometimes|boolean',
            'append_comment' => 'sometimes|boolean',
        ], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->refundOrderInMagento($vendor, $order, $store, $data, auth()->id())
        );

        try {
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'reason' => 'required|string|min:5',
                'notes' => 'nullable|string',
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
            ]);

            $vendorUuid = $request->get('vendor_uuid');
            $storeUuid = $request->get('store_uuid');
            
            // Verify store belongs to vendor
            $vendor = Vendor::where('uuid', $vendorUuid)->first();
            $store = VendorStore::where('uuid', $storeUuid)->first();
            
            if (!$vendor || !$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid vendor or store',
                ], 400);
            }
            
            if ($store->vendor_id !== $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }
            
            $order = Order::where(function($query) use ($id) {
                    $query->where('uuid', $id)
                          ->orWhere('id', $id)
                          ->orWhere('magento_order_increment_id', $id);
                })
                ->where('vendor_id', $vendor->id)
                ->where('vendor_store_id', $store->id)
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found for this vendor and store',
                ], 404);
            }

            if (!$order->canBeRefunded()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order cannot be refunded at this stage',
                ], 422);
            }

            $refundAmount = $request->amount;
            if ($refundAmount > $order->grand_total) {
                return response()->json([
                    'success' => false,
                    'message' => 'Refund amount cannot exceed order total',
                    'max_amount' => $order->grand_total,
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Create refund record
                // $refund = Refund::create([
                //     'order_id' => $order->id,
                //     'vendor_id' => $order->vendor_id,
                //     'amount' => $refundAmount,
                //     'reason' => $request->reason,
                //     'notes' => $request->notes,
                //     'status' => 'pending',
                //     'requested_by' => auth()->id(),
                //     'requested_at' => now(),
                // ]);

                // Update order status if fully refunded
                if ($refundAmount >= $order->grand_total) {
                    $order->payment_status = 'refunded';
                    $order->status = 'refunded';
                    $order->save();
                } else {
                    $order->payment_status = 'partial_refunded';
                    $order->save();
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Refund processed successfully',
                    'data' => [
                        'order_uuid' => $order->uuid,
                        'order_id' => $order->id,
                        'refund_amount' => $refundAmount,
                        'status' => 'pending',
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to process refund', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to process refund',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get order statistics
     * Requires vendor_uuid or store_uuid as query parameters
     */
    public function statistics(Request $request)
    {
        try {
            $request->validate([
                'vendor_uuid' => 'required_without:store_uuid|string|exists:vendors,uuid',
                'store_uuid' => 'required_without:vendor_uuid|string|exists:vendor_stores,uuid',
                'period' => 'sometimes|string|in:7_days,30_days,90_days,year',
            ]);

            // Validate that at least one of vendor_uuid or store_uuid is provided
            if (!$request->has('vendor_uuid') && !$request->has('store_uuid')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either vendor_uuid or store_uuid is required',
                ], 422);
            }

            $period = $request->get('period', '30_days');
            $vendorUuid = $request->get('vendor_uuid');
            $storeUuid = $request->get('store_uuid');
            
            // If store_uuid is provided, verify it belongs to vendor_uuid if both are provided
            if ($storeUuid && $vendorUuid) {
                $vendor = Vendor::where('uuid', $vendorUuid)->first();
                $store = VendorStore::where('uuid', $storeUuid)->first();
                
                if ($vendor && $store && $store->vendor_id !== $vendor->id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Store does not belong to the specified vendor',
                    ], 403);
                }
            }
            
            $statistics = $this->orderService->getOrderStatisticsFromDatabase(
                $vendorUuid,
                $storeUuid,
                $period
            );

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch order statistics', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Sync missing Magento orders into the local database for a selected vendor/store.
     */
    public function syncOrder(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
                'page_size' => 'sometimes|integer|min:1|max:100',
                'max_pages' => 'sometimes|integer|min:1|max:20',
                'status' => 'sometimes|string',
                'from_date' => 'sometimes|date',
                'to_date' => 'sometimes|date|after_or_equal:from_date',
            ]);

            $vendor = Vendor::where('uuid', $validated['vendor_uuid'])->firstOrFail();
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

            $result = $this->orderService->syncOrdersFromMagento($vendor, [
                'store_uuid' => $store->uuid,
                'magento_store_id' => $store->magento_store_id,
                'page_size' => $validated['page_size'] ?? 50,
                'max_pages' => $validated['max_pages'] ?? 5,
                'status' => $validated['status'] ?? null,
                'from_date' => $validated['from_date'] ?? null,
                'to_date' => $validated['to_date'] ?? null,
                'only_create_missing' => true,
            ]);

            return response()->json([
                'success' => $result['success'] ?? false,
                'message' => ($result['success'] ?? false)
                    ? 'Orders synchronized successfully'
                    : 'Order synchronization failed',
                'data' => $result,
            ], ($result['success'] ?? false) ? 200 : 500);

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

    public function bulkSyncOrders(Request $request)
    {
        return $this->syncOrder($request);
    }

    public function createInvoice(Request $request, $orderUuid)
    {
        return $this->runMagentoOrderOperation($request, $orderUuid, [
            'capture' => 'sometimes|boolean',
            'notify' => 'sometimes|boolean',
            'append_comment' => 'sometimes|boolean',
            'comment' => 'sometimes|string|max:1000',
        ], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->createInvoiceInMagento($vendor, $order, $store, $data)
        );
    }

    public function createShipment(Request $request, $orderUuid)
    {
        return $this->runMagentoOrderOperation($request, $orderUuid, [
            'notify' => 'sometimes|boolean',
            'append_comment' => 'sometimes|boolean',
            'comment' => 'sometimes|string|max:1000',
            'tracks' => 'sometimes|array',
            'tracks.*.track_number' => 'required_with:tracks|string|max:100',
            'tracks.*.title' => 'sometimes|string|max:100',
            'tracks.*.carrier_code' => 'sometimes|string|max:100',
        ], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->createShipmentInMagento($vendor, $order, $store, $data)
        );
    }

    public function addTracking(Request $request, $orderUuid)
    {
        return $this->runMagentoOrderOperation($request, $orderUuid, [
            'shipment_id' => 'sometimes|integer|min:1',
            'track_number' => 'required|string|max:100',
            'title' => 'sometimes|string|max:100',
            'carrier_code' => 'sometimes|string|max:100',
        ], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->addTrackingInMagento($vendor, $order, $store, $data)
        );
    }

    public function addComment(Request $request, $orderUuid)
    {
        return $this->runMagentoOrderOperation($request, $orderUuid, [
            'comment' => 'required|string|max:1000',
            'status' => 'sometimes|string|max:50',
            'is_customer_notified' => 'sometimes|boolean',
            'is_visible_on_front' => 'sometimes|boolean',
        ], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->addOrderCommentInMagento($vendor, $order, $store, $data, auth()->id())
        );
    }

    public function hold(Request $request, $orderUuid)
    {
        return $this->runMagentoOrderOperation($request, $orderUuid, [], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->holdOrderInMagento($vendor, $order, $store)
        );
    }

    public function unhold(Request $request, $orderUuid)
    {
        return $this->runMagentoOrderOperation($request, $orderUuid, [], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->unholdOrderInMagento($vendor, $order, $store)
        );
    }

    public function reorder(Request $request, $orderUuid)
    {
        return $this->runMagentoOrderOperation($request, $orderUuid, [], fn (Order $order, Vendor $vendor, VendorStore $store, array $data) =>
            $this->orderService->reorderInMagento($vendor, $order, $store)
        );
    }

    public function deleteLocal(Request $request, $orderUuid)
    {
        try {
            [$vendor, $store, $order] = $this->resolveScopedOrder($request, $orderUuid);
            $order->delete();

            return response()->json([
                'success' => true,
                'message' => 'Order deleted locally',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found for this vendor and store',
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Failed to delete local order', [
                'order' => $orderUuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete local order',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function bulkUpdateStatus(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
                'order_uuids' => 'required|array|min:1',
                'order_uuids.*' => 'required|string',
                'status' => 'required|string|max:50',
                'comment' => 'sometimes|string|max:1000',
            ]);

            $results = [];
            foreach ($validated['order_uuids'] as $orderId) {
                $operationRequest = Request::create('', 'PUT', [
                    'vendor_uuid' => $validated['vendor_uuid'],
                    'store_uuid' => $validated['store_uuid'],
                    'status' => $validated['status'],
                    'comment' => $validated['comment'] ?? null,
                ]);
                $operationRequest->setUserResolver(fn () => $request->user());

                [$vendor, $store, $order] = $this->resolveScopedOrder($operationRequest, $orderId);
                $results[] = $this->orderService->updateOrderStatusInMagento($vendor, $order, $store, [
                    'status' => $validated['status'],
                    'comment' => $validated['comment'] ?? null,
                ], auth()->id());
            }

            return response()->json([
                'success' => true,
                'message' => 'Bulk order status operation completed',
                'data' => $results,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed bulk order status update', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update bulk order statuses',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    private function runMagentoOrderOperation(Request $request, string $orderId, array $rules, callable $operation)
    {
        try {
            $validated = $request->validate(array_merge([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
            ], $rules));

            [$vendor, $store, $order] = $this->resolveScopedOrder($request, $orderId);
            $result = $operation($order, $vendor, $store, $validated);

            return response()->json([
                'success' => true,
                'message' => $result['message'] ?? 'Magento order operation completed',
                'data' => $result,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found for this vendor and store',
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Magento order operation failed', [
                'order' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Magento order operation failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    private function resolveScopedOrder(Request $request, string $orderId): array
    {
        $request->validate([
            'vendor_uuid' => 'required|string|exists:vendors,uuid',
            'store_uuid' => 'required|string|exists:vendor_stores,uuid',
        ]);

        $vendor = Vendor::where('uuid', $request->input('vendor_uuid'))->firstOrFail();
        $store = VendorStore::where('uuid', $request->input('store_uuid'))->firstOrFail();

        if ((int) $store->vendor_id !== (int) $vendor->id) {
            throw new \Exception('Store does not belong to the specified vendor');
        }

        $order = Order::where(function ($query) use ($orderId) {
                $query->where('uuid', $orderId)
                    ->orWhere('id', $orderId)
                    ->orWhere('magento_order_increment_id', $orderId);
            })
            ->where('vendor_id', $vendor->id)
            ->where('vendor_store_id', $store->id)
            ->firstOrFail();

        return [$vendor, $store, $order];
    }
}
