<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Services\Order\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerOrderController extends Controller
{
    protected $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Get customer orders
     */
    public function index(Request $request)
    {
        $customer = $request->user();

        $query = Order::where('customer_id', $customer->id)
            ->orWhere('customer_email', $customer->email)
            ->with(['items', 'vendor', 'vendorStore', 'tracking']);

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
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
            ],
        ]);
    }

    /**
     * Get single order
     */
    public function show($id)
    {
        $customer = auth()->user();

        $order = Order::where(function ($query) use ($customer) {
            $query->where('customer_id', $customer->id)
                ->orWhere('customer_email', $customer->email);
        })
            ->with(['items', 'vendor', 'vendorStore', 'tracking', 'statusHistory', 'paymentTransactions'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * Cancel order
     */
    public function cancel(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $customer = auth()->user();

        $order = Order::where(function ($query) use ($customer) {
            $query->where('customer_id', $customer->id)
                ->orWhere('customer_email', $customer->email);
        })
            ->whereIn('status', ['pending', 'processing'])
            ->findOrFail($id);

        if (! $order->canBeCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'Order cannot be cancelled at this stage',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $order->updateStatus('cancelled', $request->reason, $customer);

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
     * Get order tracking information
     */
    public function tracking($id)
    {
        $customer = auth()->user();

        $order = Order::where(function ($query) use ($customer) {
            $query->where('customer_id', $customer->id)
                ->orWhere('customer_email', $customer->email);
        })
            ->with(['tracking', 'tracking.carrier'])
            ->findOrFail($id);

        if (! $order->tracking) {
            return response()->json([
                'success' => false,
                'message' => 'No tracking information available',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'tracking' => [
                    'number' => $order->tracking->tracking_number,
                    'url' => $order->tracking->tracking_url,
                    'carrier' => $order->tracking->carrier?->name,
                    'status' => $order->tracking->status,
                    'estimated_delivery' => $order->tracking->estimated_delivery,
                    'events' => $order->tracking->tracking_events,
                ],
            ],
        ]);
    }

    /**
     * Download order invoice
     */
    public function downloadInvoice($id)
    {
        $customer = auth()->user();

        $order = Order::where(function ($query) use ($customer) {
            $query->where('customer_id', $customer->id)
                ->orWhere('customer_email', $customer->email);
        })
            ->findOrFail($id);

        $invoice = $order->invoices()->where('type', 'customer_invoice')->first();

        if (! $invoice || ! $invoice->pdf_path) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not available',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'download_url' => $invoice->getPdfUrl(),
                'invoice_number' => $invoice->invoice_number,
            ],
        ]);
    }
}
