<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Models\Return\ReturnModel;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    protected $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Get all orders with filters
     */
    public function index(Request $request)
    {
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

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
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

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('order_number', 'like', '%'.$request->search.'%')
                    ->orWhere('customer_email', 'like', '%'.$request->search.'%')
                    ->orWhere('customer_firstname', 'like', '%'.$request->search.'%')
                    ->orWhere('customer_lastname', 'like', '%'.$request->search.'%')
                    ->orWhere('magento_order_increment_id', 'like', '%'.$request->search.'%');
            });
        }

        $orders = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        // Add summary statistics
        $summary = [
            'total_orders' => Order::count(),
            'total_revenue' => Order::where('status', '!=', 'cancelled')->sum('grand_total'),
            'total_commission' => Order::where('status', '!=', 'cancelled')->sum('commission_amount'),
            'average_order_value' => Order::where('status', '!=', 'cancelled')->avg('grand_total'),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'processing_orders' => Order::where('status', 'processing')->count(),
            'shipped_orders' => Order::where('status', 'shipped')->count(),
            'delivered_orders' => Order::where('status', 'delivered')->count(),
            'cancelled_orders' => Order::where('status', 'cancelled')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($orders),
            'summary' => $summary,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Get single order details
     */
    public function show($id)
    {
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

        // Get order timeline
        $timeline = $this->orderService->getOrderTimeline($order);

        // Get return requests for this order
        $returns = ReturnModel::where('order_id', $order->id)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'order' => new OrderResource($order),
                'timeline' => $timeline,
                'returns' => $returns,
                'can_cancel' => $order->canBeCancelled(),
                'can_refund' => $order->canBeRefunded(),
            ],
        ]);
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'notes' => 'nullable|string',
        ]);

        $order = Order::findOrFail($id);

        DB::beginTransaction();

        try {
            $order->updateStatus($request->status, $request->notes, auth()->user());

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

            return response()->json([
                'success' => false,
                'message' => 'Failed to update order status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus(Request $request, $id)
    {
        $request->validate([
            'payment_status' => 'required|in:pending,paid,refunded,chargeback,failed',
            'notes' => 'nullable|string',
        ]);

        $order = Order::findOrFail($id);

        DB::beginTransaction();

        try {
            $order->updatePaymentStatus($request->payment_status, $request->notes);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated successfully',
                'data' => [
                    'order_id' => $order->id,
                    'payment_status' => $order->payment_status,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel order
     */
    public function cancel(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $order = Order::findOrFail($id);

        if (! $order->canBeCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be cancelled at this stage',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->orderService->cancelOrder($order, $request->reason, auth()->user());

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

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add note to order
     */
    public function addNote(Request $request, $id)
    {
        $request->validate([
            'note' => 'required|string',
            'customer_visible' => 'boolean',
        ]);

        $order = Order::findOrFail($id);

        $notes = $order->admin_notes ?? [];
        $notes[] = [
            'note' => $request->note,
            'customer_visible' => $request->customer_visible ?? false,
            'created_by' => auth()->user()->full_name,
            'created_at' => now()->toIso8601String(),
        ];

        $order->admin_notes = $notes;
        $order->save();

        return response()->json([
            'success' => true,
            'message' => 'Note added successfully',
            'data' => [
                'notes' => $order->admin_notes,
            ],
        ]);
    }

    /**
     * Get order statistics
     */
    public function statistics(Request $request)
    {
        $period = $request->get('period', '30_days');
        $startDate = match ($period) {
            '7_days' => now()->subDays(7),
            '30_days' => now()->subDays(30),
            '90_days' => now()->subDays(90),
            'year' => now()->subYear(),
            default => now()->subDays(30),
        };

        // Daily orders and revenue
        $dailyStats = Order::where('created_at', '>=', $startDate)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(grand_total) as revenue'),
                DB::raw('AVG(grand_total) as avg_order_value')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Orders by status
        $ordersByStatus = Order::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        // Orders by payment status
        $ordersByPaymentStatus = Order::select('payment_status', DB::raw('COUNT(*) as count'))
            ->groupBy('payment_status')
            ->get();

        // Top vendors by revenue
        $topVendors = Order::where('created_at', '>=', $startDate)
            ->where('status', '!=', 'cancelled')
            ->select('vendor_id', DB::raw('SUM(grand_total) as revenue'), DB::raw('COUNT(*) as order_count'))
            ->with('vendor')
            ->groupBy('vendor_id')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();

        // Top products
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
            ->where('orders.created_at', '>=', $startDate)
            ->where('orders.status', '!=', 'cancelled')
            ->select(
                'vendor_products.name',
                DB::raw('SUM(order_items.qty_ordered) as quantity_sold'),
                DB::raw('SUM(order_items.row_total) as revenue')
            )
            ->groupBy('vendor_products.id', 'vendor_products.name')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();

        // Hourly order distribution (last 7 days)
        $hourlyDistribution = Order::where('created_at', '>=', now()->subDays(7))
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as count'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'daily_stats' => $dailyStats,
                'orders_by_status' => $ordersByStatus,
                'orders_by_payment_status' => $ordersByPaymentStatus,
                'top_vendors' => $topVendors,
                'top_products' => $topProducts,
                'hourly_distribution' => $hourlyDistribution,
                'period' => $period,
                'start_date' => $startDate->toDateString(),
                'end_date' => now()->toDateString(),
            ],
        ]);
    }

    /**
     * Export orders to CSV
     */
    public function export(Request $request)
    {
        $query = Order::with(['vendor', 'customer']);

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

        $orders = $query->get();

        $filename = 'orders_export_'.date('Y-m-d_His').'.csv';
        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'Order ID', 'Order Number', 'Customer Name', 'Customer Email',
            'Status', 'Payment Status', 'Subtotal', 'Tax', 'Shipping',
            'Discount', 'Total', 'Currency', 'Payment Method',
            'Vendor', 'Created At', 'Shipped At', 'Delivered At',
        ]);

        // Data
        foreach ($orders as $order) {
            fputcsv($handle, [
                $order->id,
                $order->order_number,
                $order->customer_firstname.' '.$order->customer_lastname,
                $order->customer_email,
                $order->status,
                $order->payment_status,
                $order->subtotal,
                $order->tax_amount,
                $order->shipping_amount,
                $order->discount_amount,
                $order->grand_total,
                $order->currency_code,
                $order->payment_method,
                $order->vendor?->company_name,
                $order->created_at,
                $order->shipped_at,
                $order->delivered_at,
            ]);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $filename,
                'content' => base64_encode($csvContent),
                'mime_type' => 'text/csv',
                'row_count' => $orders->count(),
            ],
        ]);
    }

    /**
     * Placeholder until admin refund processing is implemented.
     */
    public function processRefund(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Order refund processing is not implemented yet.',
            'order_id' => $id,
        ], 501);
    }
}
