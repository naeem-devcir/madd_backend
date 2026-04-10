<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Order\Return as ReturnModel;
use App\Models\Return\ReturnItem;
use App\Services\Order\ReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturnController extends Controller
{
    protected $returnService;

    public function __construct(ReturnService $returnService)
    {
        $this->returnService = $returnService;
    }

    /**
     * Get customer returns
     */
    public function index(Request $request)
    {
        $customer = auth()->user();

        $returns = ReturnModel::where('customer_id', $customer->uuid)
            ->with(['order', 'items', 'courier'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $returns
        ]);
    }

    /**
     * Create a return request
     */
    public function create(Request $request, $orderId)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:1000',
        ]);

        $customer = auth()->user();

        $order = Order::where('customer_id', $customer->uuid)
            ->whereIn('status', ['delivered', 'shipped'])
            ->findOrFail($orderId);

        // Check return window (e.g., 14 days)
        $returnWindowDays = $order->vendorStore->salesPolicy->return_window_days ?? 14;
        if ($order->delivered_at && $order->delivered_at->diffInDays(now()) > $returnWindowDays) {
            return response()->json([
                'success' => false,
                'message' => 'Return window has expired'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $return = ReturnModel::create([
                'order_id' => $order->uuid,
                'customer_id' => $customer->uuid,
                'vendor_id' => $order->vendor_id,
                'status' => 'requested',
                'reason' => $request->reason,
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $item) {
                $orderItem = \App\Models\Order\OrderItem::findOrFail($item['order_item_id']);

                ReturnItem::create([
                    'return_id' => $return->uuid,
                    'order_item_id' => $orderItem->uuid,
                    'quantity' => $item['quantity'],
                    'reason' => $item['reason'] ?? $request->reason,
                ]);
            }

            DB::commit();

            // Notify vendor
            \App\Jobs\Notification\SendReturnRequestNotification::dispatch($return);

            return response()->json([
                'success' => true,
                'message' => 'Return request submitted successfully',
                'data' => [
                    'return_id' => $return->id,
                    'rma_number' => $return->rma_number,
                    'status' => $return->status,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create return request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get return details
     */
    public function show($id)
    {
        $customer = auth()->user();

        $return = ReturnModel::where('customer_id', $customer->uuid)
            ->with(['order', 'items.orderItem', 'courier'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $return
        ]);
    }

    /**
     * Cancel return request
     */
    public function cancel($id)
    {
        $customer = auth()->user();

        $return = ReturnModel::where('customer_id', $customer->uuid)
            ->where('status', 'requested')
            ->findOrFail($id);

        DB::beginTransaction();

        try {
            $return->status = 'cancelled';
            $return->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Return request cancelled'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel return',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download return label
     */
    public function downloadLabel($id)
    {
        $customer = auth()->user();

        $return = ReturnModel::where('customer_id', $customer->uuid)
            ->where('status', 'approved')
            ->findOrFail($id);

        if (!$return->return_label_url) {
            return response()->json([
                'success' => false,
                'message' => 'Return label not available'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'label_url' => $return->return_label_url,
                'tracking_number' => $return->tracking_number,
            ]
        ]);
    }
}
