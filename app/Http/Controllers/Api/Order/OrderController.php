<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Jobs\Report\GenerateExportJob;
use App\Models\Order\Order;
use App\Services\Order\OrderService;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    protected $orderService;

    protected $paymentService;

    public function __construct(OrderService $orderService, PaymentService $paymentService)
    {
        $this->orderService = $orderService;
        $this->paymentService = $paymentService;
    }

    /**
     * Get all orders (admin view)
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

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('magento_order_increment_id', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_firstname', 'like', "%{$search}%")
                    ->orWhere('customer_lastname', 'like', "%{$search}%");
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
                'filters' => $request->only(['status', 'payment_status', 'vendor_id', 'date_from', 'date_to']),
            ],
        ]);
    }

    /**
     * Get single order
     */
    public function show($id)
    {
        $order = Order::with([
            'vendor',
            'vendorStore',
            'customer',
            'items',
            'items.vendorProduct',
            'tracking',
            'tracking.carrier',
            'statusHistory',
            'paymentTransactions',
            'refunds',
            'settlement',
        ])->findOrFail($id);

        // Get order timeline
        $timeline = $this->orderService->getOrderTimeline($order);

        return response()->json([
            'success' => true,
            'data' => [
                'order' => new OrderResource($order),
                'timeline' => $timeline,
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
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = Order::findOrFail($id);
        $oldStatus = $order->status;

        DB::beginTransaction();

        try {
            $order->updateStatus($request->status, $request->notes, auth()->user());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'data' => [
                    'order_id' => $order->id,
                    'old_status' => $oldStatus,
                    'new_status' => $order->status,
                    'notes' => $request->notes,
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
     * Bulk update order statuses
     */
    public function bulkUpdateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:orders,id',
            'status' => 'required|in:pending,processing,shipped,delivered,cancelled,refunded',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $orders = Order::whereIn('id', $request->order_ids)->get();
        $updatedCount = 0;
        $failedOrders = [];

        DB::beginTransaction();

        try {
            foreach ($orders as $order) {
                try {
                    $order->updateStatus($request->status, $request->notes, auth()->user());
                    $updatedCount++;
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
                'message' => "{$updatedCount} orders updated successfully",
                'data' => [
                    'updated_count' => $updatedCount,
                    'failed_orders' => $failedOrders,
                    'status' => $request->status,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Cancel order
     */
    public function cancel(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

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
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'cancelled_at' => now()->toIso8601String(),
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
     * Get order statistics
     */
    public function statistics(Request $request)
    {
        $query = Order::query();

        // Apply date filters
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $stats = [
            'total_orders' => $query->count(),
            'total_revenue' => $query->sum('grand_total'),
            'average_order_value' => $query->avg('grand_total') ?? 0,
            'total_commission' => $query->sum('commission_amount'),
            'total_refunds' => $query->sum('total_refunded_amount'),

            'by_status' => [
                'pending' => (clone $query)->where('status', 'pending')->count(),
                'processing' => (clone $query)->where('status', 'processing')->count(),
                'shipped' => (clone $query)->where('status', 'shipped')->count(),
                'delivered' => (clone $query)->where('status', 'delivered')->count(),
                'cancelled' => (clone $query)->where('status', 'cancelled')->count(),
                'refunded' => (clone $query)->where('status', 'refunded')->count(),
            ],

            'by_payment_status' => [
                'pending' => (clone $query)->where('payment_status', 'pending')->count(),
                'paid' => (clone $query)->where('payment_status', 'paid')->count(),
                'refunded' => (clone $query)->where('payment_status', 'refunded')->count(),
                'failed' => (clone $query)->where('payment_status', 'failed')->count(),
                'chargeback' => (clone $query)->where('payment_status', 'chargeback')->count(),
            ],
        ];

        // Get daily sales for chart
        $dailySales = (clone $query)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(grand_total) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        // Get top products
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.uuid')
            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
            ->select('vendor_products.name', DB::raw('SUM(order_items.qty_ordered) as total_quantity'), DB::raw('SUM(order_items.row_total) as total_revenue'))
            ->groupBy('vendor_products.id', 'vendor_products.name')
            ->orderBy('total_revenue', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $stats,
                'daily_sales' => $dailySales,
                'top_products' => $topProducts,
            ],
        ]);
    }

    /**
     * Export orders to CSV/Excel
     */
    public function export(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'format' => 'required|in:csv,xlsx',
            'status' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = Order::with(['vendor', 'customer', 'items']);

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

        // Generate export file
        $exportData = $orders->map(function ($order) {
            return [
                'Order Number' => $order->order_number,
                'Customer Email' => $order->customer_email,
                'Customer Name' => $order->customer_firstname.' '.$order->customer_lastname,
                'Status' => $order->status,
                'Payment Status' => $order->payment_status,
                'Subtotal' => $order->subtotal,
                'Tax' => $order->tax_amount,
                'Shipping' => $order->shipping_amount,
                'Discount' => $order->discount_amount,
                'Total' => $order->grand_total,
                'Commission' => $order->commission_amount,
                'Vendor Payout' => $order->vendor_payout_amount,
                'Payment Method' => $order->payment_method,
                'Shipping Method' => $order->shipping_method,
                'Created At' => $order->created_at->toDateTimeString(),
                'Items Count' => $order->items->count(),
            ];
        });

        // Dispatch job to generate export file
        $exportJob = new GenerateExportJob($exportData, $request->format, auth()->user());
        dispatch($exportJob);

        return response()->json([
            'success' => true,
            'message' => 'Export job queued successfully. You will receive a download link via email.',
            'data' => [
                'record_count' => $exportData->count(),
                'format' => $request->format,
            ],
        ]);
    }

    /**
     * Get order invoice
     */
    public function invoice($id)
    {
        $order = Order::findOrFail($id);

        $invoice = $order->invoices()->where('type', 'customer_invoice')->first();

        if (! $invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found for this order',
            ], 404);
        }

        if (! $invoice->pdf_path) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice PDF not generated yet',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'invoice_number' => $invoice->invoice_number,
                'download_url' => $invoice->getPdfUrl(),
                'issued_at' => $invoice->issued_at->toDateString(),
                'total' => $invoice->total,
                'currency' => $invoice->currency_code,
            ],
        ]);
    }
}
