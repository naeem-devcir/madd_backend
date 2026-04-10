<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Models\Order\OrderTracking;
use App\Models\Config\Courier;
use App\Services\Order\OrderService;
use App\Services\Shipping\LabelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorOrderController extends Controller
{
    protected $orderService;
    protected $labelService;

    public function __construct(OrderService $orderService, LabelService $labelService)
    {
        $this->orderService = $orderService;
        $this->labelService = $labelService;
    }

    /**
     * Get all orders for the vendor
     */
    public function index(Request $request)
    {
        $vendor = $request->user()->vendor;

        $query = Order::where('vendor_id', $vendor->getKey())
            ->with(['items', 'customer', 'tracking', 'vendorStore']);

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('fulfillment_status')) {
            $query->where('fulfillment_status', $request->fulfillment_status);
        }

        if ($request->has('store_id')) {
            $query->whereHas('vendorStore', function ($storeQuery) use ($request) {
                $storeQuery->where('id', $request->store_id);
            });
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->has('search')) {
            $query->where(function($q) use ($request) {
                $q->where('order_number', 'like', '%' . $request->search . '%')
                  ->orWhere('customer_email', 'like', '%' . $request->search . '%')
                  ->orWhere('customer_firstname', 'like', '%' . $request->search . '%')
                  ->orWhere('customer_lastname', 'like', '%' . $request->search . '%');
            });
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($orders),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
                'filters' => $request->only(['status', 'payment_status', 'store_id', 'date_from', 'date_to']),
            ]
        ]);
    }

    /**
     * Get single order
     */
    public function show(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $order = Order::where('vendor_id', $vendor->getKey())
            ->with(['items', 'customer', 'tracking', 'tracking.carrier', 'statusHistory', 'paymentTransactions', 'vendorStore'])
            ->findOrFail($id);

        // Get order timeline
        $timeline = $this->orderService->getOrderTimeline($order);

        return response()->json([
            'success' => true,
            'data' => [
                'order' => new OrderResource($order),
                'timeline' => $timeline,
                'can_cancel' => $order->canBeCancelled(),
                'can_refund' => $order->canBeRefunded(),
                'can_ship' => $order->status === 'processing',
            ]
        ]);
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:processing,shipped,cancelled',
            'notes' => 'nullable|string|max:500',
        ]);

        $vendor = $request->user()->vendor;

        $order = Order::where('vendor_id', $vendor->getKey())
            ->whereIn('status', ['pending', 'processing'])
            ->findOrFail($id);

        DB::beginTransaction();

        try {
            if ($request->status === 'cancelled') {
                $this->orderService->cancelOrder($order, $request->notes ?? 'Cancelled by vendor', $request->user());
            } else {
                $order->updateStatus($request->status, $request->notes, $request->user());
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => [
                    'order_id' => $order->id,
                    'status' => $order->status,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create shipment for order
     */
    public function createShipment(Request $request, $id)
    {
        $request->validate([
            'carrier_id' => 'required|exists:couriers,id',
            'tracking_number' => 'required|string|max:100',
            'generate_label' => 'boolean',
        ]);

        $vendor = $request->user()->vendor;

        $order = Order::where('vendor_id', $vendor->getKey())
            ->where('status', 'processing')
            ->findOrFail($id);

        DB::beginTransaction();

        try {
            $carrier = Courier::find($request->carrier_id);

            // Generate shipping label if requested
            $labelUrl = null;
            if ($request->generate_label) {
                $label = $this->labelService->generateLabel($order, $carrier);
                $labelUrl = $label['label_url'] ?? null;
            }

            // Mark as shipped
            $order->markAsShipped($request->tracking_number, $carrier->uuid);

            // Store tracking info
            OrderTracking::create([
                'order_id' => $order->uuid,
                'carrier_id' => $carrier->uuid,
                'tracking_number' => $request->tracking_number,
                'label_url' => $labelUrl,
                'status' => 'shipped',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shipment created successfully',
                'data' => [
                    'order_id' => $order->id,
                    'tracking_number' => $request->tracking_number,
                    'label_url' => $labelUrl,
                    'carrier' => $carrier->name,
                    'tracking_url' => $carrier->getTrackingUrl($request->tracking_number),
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create shipment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update order status
     */
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'exists:orders,id',
            'status' => 'required|in:processing,shipped,cancelled',
        ]);

        $vendor = $request->user()->vendor;

        $orders = Order::where('vendor_id', $vendor->getKey())
            ->whereIn('id', $request->order_ids)
            ->whereIn('status', ['pending', 'processing'])
            ->get();

        $successCount = 0;
        $failedOrders = [];

        DB::beginTransaction();

        try {
            foreach ($orders as $order) {
                try {
                    if ($request->status === 'cancelled') {
                        $this->orderService->cancelOrder($order, 'Bulk cancellation', $request->user());
                    } else {
                        $order->updateStatus($request->status, 'Bulk update', $request->user());
                    }
                    $successCount++;
                } catch (\Exception $e) {
                    $failedOrders[] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$successCount} orders updated successfully",
                'data' => [
                    'success_count' => $successCount,
                    'failed_count' => count($failedOrders),
                    'failed_orders' => $failedOrders,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate shipping label
     */
    public function generateLabel(Request $request, $id)
    {
        $request->validate([
            'carrier_id' => 'required|exists:couriers,id',
        ]);

        $vendor = $request->user()->vendor;

        $order = Order::where('vendor_id', $vendor->getKey())
            ->where('status', 'processing')
            ->findOrFail($id);

        $carrier = Courier::find($request->carrier_id);

        try {
            $label = $this->labelService->generateLabel($order, $carrier);

            return response()->json([
                'success' => true,
                'data' => [
                    'label_url' => $label['label_url'],
                    'tracking_number' => $label['tracking_number'],
                    'carrier' => $carrier->name,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate label',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get order statistics
     */
    public function statistics(Request $request)
    {
        $vendor = $request->user()->vendor;

        $stats = $this->orderService->getVendorOrderStats($vendor, $request->get('period', 'month'));

        // Get recent orders
        $recentOrders = Order::where('vendor_id', $vendor->getKey())
            ->with(['items', 'customer'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get pending actions count
        $pendingActions = [
            'pending_orders' => Order::where('vendor_id', $vendor->getKey())->where('status', 'pending')->count(),
            'processing_orders' => Order::where('vendor_id', $vendor->getKey())->where('status', 'processing')->count(),
            'to_ship' => Order::where('vendor_id', $vendor->getKey())->where('status', 'processing')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $stats,
                'pending_actions' => $pendingActions,
                'recent_orders' => OrderResource::collection($recentOrders),
            ]
        ]);
    }

    /**
     * Export orders
     */
    public function export(Request $request)
    {
        $vendor = $request->user()->vendor;

        $query = Order::where('vendor_id', $vendor->getKey());

        // Apply same filters as index
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $orders = $query->with(['items'])->get();

        // Generate CSV export
        $csvData = $this->generateCsv($orders);

        return response()->json([
            'success' => true,
            'data' => [
                'csv_data' => $csvData,
                'total_records' => $orders->count(),
                'export_date' => now()->toIso8601String(),
            ]
        ]);
    }

    /**
     * Generate CSV data for export
     */
    private function generateCsv($orders)
    {
        $headers = [
            'Order ID', 'Date', 'Customer', 'Email', 'Total', 'Status', 
            'Payment Status', 'Items Count', 'Shipping Method', 'Tracking Number'
        ];

        $rows = [];

        foreach ($orders as $order) {
            $rows[] = [
                $order->order_number,
                $order->created_at->format('Y-m-d H:i:s'),
                $order->customer_firstname . ' ' . $order->customer_lastname,
                $order->customer_email,
                $order->grand_total,
                $order->status,
                $order->payment_status,
                $order->items->sum('qty_ordered'),
                $order->shipping_method,
                $order->tracking_number,
            ];
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    /**
     * Placeholder until invoice generation is implemented.
     */
    public function generateInvoice(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order invoice generation is not implemented yet.',
            'order_id' => $id,
        ], 501);
    }

    /**
     * Placeholder until invoice download is implemented.
     */
    public function downloadInvoice(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order invoice download is not implemented yet.',
            'order_id' => $id,
        ], 501);
    }
}
