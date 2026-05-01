<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdminOrderController extends Controller
{
    /**
     * Get all orders with filters and pagination
     */
    public function index(Request $request)
    {
        try {
            $request->validate([
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|in:pending,processing,shipped,delivered,cancelled,refunded',
                'payment_status' => 'sometimes|string|in:pending,paid,refunded,chargeback,failed',
                'vendor_id' => 'sometimes|string|exists:vendors,id',
                'vendor_store_id' => 'sometimes|integer|exists:vendor_stores,id',
                'search' => 'sometimes|string|min:2',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
                'amount_min' => 'sometimes|numeric|min:0',
                'amount_max' => 'sometimes|numeric|min:0',
            ]);

            // Use correct relationship names: vendorStore instead of store
            $query = Order::with(['vendor', 'vendorStore', 'customer', 'items']);

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('payment_status')) {
                $query->where('payment_status', $request->payment_status);
            }

            if ($request->has('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            if ($request->has('vendor_store_id')) {
                $query->where('vendor_store_id', $request->vendor_store_id);
            }

            if ($request->has('search') && !empty($request->search)) {
                $searchTerm = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('magento_order_increment_id', 'like', $searchTerm)
                        ->orWhere('customer_email', 'like', $searchTerm)
                        ->orWhere('customer_firstname', 'like', $searchTerm)
                        ->orWhere('customer_lastname', 'like', $searchTerm);
                });
            }

            if ($request->has('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            if ($request->has('amount_min')) {
                $query->where('grand_total', '>=', $request->amount_min);
            }

            if ($request->has('amount_max')) {
                $query->where('grand_total', '<=', $request->amount_max);
            }

            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100);
            
            $orders = $query->orderBy('created_at', 'desc')
                ->paginate($perPage)
                ->appends($request->query());

            // Summary statistics
            $summaryQuery = clone $query;
            $summary = [
                'total_orders' => $summaryQuery->count(),
                'total_revenue' => $summaryQuery->where('status', '!=', 'cancelled')->sum('grand_total'),
                'average_order_value' => $summaryQuery->where('status', '!=', 'cancelled')->avg('grand_total'),
                'pending_orders' => (clone $summaryQuery)->where('status', 'pending')->count(),
                'processing_orders' => (clone $summaryQuery)->where('status', 'processing')->count(),
                'shipped_orders' => (clone $summaryQuery)->where('status', 'shipped')->count(),
                'delivered_orders' => (clone $summaryQuery)->where('status', 'delivered')->count(),
                'cancelled_orders' => (clone $summaryQuery)->where('status', 'cancelled')->count(),
            ];

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
     * Get orders by store (vendor store)
     */
    public function getOrdersByStore(Request $request, $storeId)
    {
        try {
            $request->validate([
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|in:pending,processing,shipped,delivered,cancelled,refunded',
            ]);

            // Check if store exists
            $store = VendorStore::find($storeId);
            if (!$store) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store not found',
                ], 404);
            }
            
            $query = Order::where('vendor_store_id', $storeId)
                ->with(['vendor', 'vendorStore', 'customer', 'items']);
            
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100);
            
            $orders = $query->orderBy('created_at', 'desc')
                ->paginate($perPage)
                ->appends($request->query());
            
            return response()->json([
                'success' => true,
                'data' => OrderResource::collection($orders),
                'store_info' => [
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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found',
            ], 404);
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
     * Get orders by vendor
     */
    public function getOrdersByVendor(Request $request, $vendorId)
    {
        try {
            $request->validate([
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'status' => 'sometimes|string|in:pending,processing,shipped,delivered,cancelled,refunded',
            ]);

            // Check if vendor exists
            $vendor = Vendor::find($vendorId);
            if (!$vendor) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vendor not found',
                ], 404);
            }
            
            $query = Order::where('vendor_id', $vendorId)
                ->with(['vendor', 'vendorStore', 'customer', 'items']);
            
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 100);
            
            $orders = $query->orderBy('created_at', 'desc')
                ->paginate($perPage)
                ->appends($request->query());
            
            return response()->json([
                'success' => true,
                'data' => OrderResource::collection($orders),
                'vendor_info' => [
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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor not found',
            ], 404);
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
     */
    public function show($id)
    {
        try {
            $order = Order::with([
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
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => new OrderResource($order),
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
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
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
                'notes' => 'nullable|string',
            ]);

            $order = Order::findOrFail($id);

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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
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
     */
    public function cancel(Request $request, $id)
    {
        try {
            $request->validate([
                'reason' => 'required|string|min:5',
                'notes' => 'nullable|string',
            ]);

            $order = Order::findOrFail($id);

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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
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
     */
    public function processRefund(Request $request, $id)
    {
        try {
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'reason' => 'required|string|min:5',
                'notes' => 'nullable|string',
            ]);

            $order = Order::findOrFail($id);

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
                $refund = Refund::create([
                    'order_id' => $order->id,
                    'vendor_id' => $order->vendor_id,
                    'amount' => $refundAmount,
                    'reason' => $request->reason,
                    'notes' => $request->notes,
                    'status' => 'pending',
                    'requested_by' => auth()->id(),
                    'requested_at' => now(),
                ]);

                // Update order status if fully refunded
                if ($refundAmount >= $order->grand_total) {
                    $order->payment_status = 'refunded';
                    $order->status = 'refunded';
                    $order->save();
                } else {
                    $order->payment_status = 'refunded';
                    $order->save();
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Refund processed successfully',
                    'data' => [
                        'refund_id' => $refund->id,
                        'amount' => $refundAmount,
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
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
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
     */
    public function statistics(Request $request)
    {
        try {
            $period = $request->get('period', '30_days');
            $startDate = match ($period) {
                '7_days' => now()->subDays(7),
                '30_days' => now()->subDays(30),
                '90_days' => now()->subDays(90),
                'year' => now()->subYear(),
                default => now()->subDays(30),
            };

            $summary = [
                'total_orders' => Order::count(),
                'total_revenue' => Order::where('status', '!=', 'cancelled')->sum('grand_total'),
                'average_order_value' => Order::where('status', '!=', 'cancelled')->avg('grand_total'),
                'pending_orders' => Order::where('status', 'pending')->count(),
                'processing_orders' => Order::where('status', 'processing')->count(),
                'shipped_orders' => Order::where('status', 'shipped')->count(),
                'delivered_orders' => Order::where('status', 'delivered')->count(),
                'cancelled_orders' => Order::where('status', 'cancelled')->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => $summary,
                'period' => $period,
                'start_date' => $startDate->toDateString(),
                'end_date' => now()->toDateString(),
            ]);

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
}