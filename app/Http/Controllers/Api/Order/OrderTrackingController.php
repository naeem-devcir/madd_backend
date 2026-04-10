<?php

namespace App\Http\Controllers\Api\Order;

use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Order\OrderTracking;
use App\Models\Config\Courier;
use App\Services\Shipping\TrackingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderTrackingController extends Controller
{
    protected $trackingService;

    public function __construct(TrackingService $trackingService)
    {
        $this->trackingService = $trackingService;
    }

    /**
     * Get tracking information for an order
     */
    public function show($orderId)
    {
        $order = Order::with(['tracking', 'tracking.carrier'])->findOrFail($orderId);

        if (!$order->tracking) {
            return response()->json([
                'success' => false,
                'message' => 'No tracking information available for this order'
            ], 404);
        }

        // Update tracking from carrier if needed
        if ($order->tracking->status !== 'delivered') {
            $this->trackingService->updateTracking($order->tracking);
            $order->tracking->refresh();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'tracking_number' => $order->tracking->tracking_number,
                'tracking_url' => $order->tracking->tracking_url,
                'carrier' => [
                    'name' => $order->tracking->carrier?->name,
                    'code' => $order->tracking->carrier?->code,
                    'logo_url' => $order->tracking->carrier?->logo_url,
                ],
                'status' => $order->tracking->status,
                'estimated_delivery' => $order->tracking->estimated_delivery?->toDateString(),
                'delivered_at' => $order->tracking->delivered_at?->toIso8601String(),
                'last_update' => $order->tracking->last_update?->toIso8601String(),
                'events' => $order->tracking->tracking_events ?? [],
            ]
        ]);
    }

    /**
     * Create tracking for an order (vendor/admin)
     */
    public function store(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'carrier_id' => 'required|exists:couriers,id',
            'tracking_number' => 'required|string|max:255',
            'label_url' => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($orderId);

        // Check if tracking already exists
        if ($order->tracking) {
            return response()->json([
                'success' => false,
                'message' => 'Tracking already exists for this order'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $carrier = Courier::find($request->carrier_id);
            
            $tracking = OrderTracking::create([
                'order_id' => $order->uuid,
                'carrier_id' => $carrier->uuid,
                'tracking_number' => $request->tracking_number,
                'label_url' => $request->label_url,
                'status' => 'pending',
                'tracking_url' => $carrier->getTrackingUrl($request->tracking_number),
            ]);

            // Update order status
            $order->updateStatus('shipped', 'Order has been shipped with tracking number: ' . $request->tracking_number, auth()->user());

            DB::commit();

            // Send notification to customer
            \App\Jobs\Notification\SendOrderShippedNotification::dispatch($order);

            return response()->json([
                'success' => true,
                'message' => 'Tracking created successfully',
                'data' => [
                    'tracking_id' => $tracking->id,
                    'tracking_number' => $tracking->tracking_number,
                    'tracking_url' => $tracking->tracking_url,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create tracking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update tracking information
     */
    public function update(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'tracking_number' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:pending,in_transit,out_for_delivery,delivered,failed',
            'estimated_delivery' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($orderId);

        if (!$order->tracking) {
            return response()->json([
                'success' => false,
                'message' => 'No tracking found for this order'
            ], 404);
        }

        DB::beginTransaction();

        try {
            $tracking = $order->tracking;
            
            if ($request->has('tracking_number')) {
                $tracking->tracking_number = $request->tracking_number;
                if ($tracking->carrier) {
                    $tracking->tracking_url = $tracking->carrier->getTrackingUrl($request->tracking_number);
                }
            }

            if ($request->has('status')) {
                $tracking->status = $request->status;
                
                if ($request->status === 'delivered') {
                    $tracking->delivered_at = now();
                    $order->markAsDelivered();
                }
            }

            if ($request->has('estimated_delivery')) {
                $tracking->estimated_delivery = $request->estimated_delivery;
            }

            $tracking->last_update = now();
            $tracking->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tracking updated successfully',
                'data' => [
                    'tracking_number' => $tracking->tracking_number,
                    'status' => $tracking->status,
                    'estimated_delivery' => $tracking->estimated_delivery?->toDateString(),
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update tracking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add tracking event
     */
    public function addEvent(Request $request, $orderId)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|max:100',
            'location' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $order = Order::findOrFail($orderId);

        if (!$order->tracking) {
            return response()->json([
                'success' => false,
                'message' => 'No tracking found for this order'
            ], 404);
        }

        $tracking = $order->tracking;
        
        $events = $tracking->tracking_events ?? [];
        $events[] = [
            'status' => $request->status,
            'location' => $request->location,
            'description' => $request->description,
            'timestamp' => now()->toIso8601String(),
        ];

        $tracking->tracking_events = $events;
        $tracking->status = $request->status;
        $tracking->last_update = now();
        $tracking->save();

        // If delivered, update order
        if ($request->status === 'delivered' && !$order->is_delivered) {
            $order->markAsDelivered();
        }

        return response()->json([
            'success' => true,
            'message' => 'Tracking event added',
            'data' => [
                'event' => $events[count($events) - 1],
                'total_events' => count($events),
            ]
        ]);
    }

    /**
     * Refresh tracking from carrier
     */
    public function refresh($orderId)
    {
        $order = Order::with('tracking')->findOrFail($orderId);

        if (!$order->tracking) {
            return response()->json([
                'success' => false,
                'message' => 'No tracking found for this order'
            ], 404);
        }

        try {
            $updated = $this->trackingService->updateTracking($order->tracking);

            return response()->json([
                'success' => true,
                'message' => 'Tracking refreshed successfully',
                'data' => [
                    'status' => $order->tracking->status,
                    'events' => $order->tracking->tracking_events,
                    'updated' => $updated,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh tracking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk create tracking for multiple orders
     */
    public function bulkCreate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'orders' => 'required|array',
            'orders.*.order_id' => 'required|exists:orders,id',
            'orders.*.carrier_id' => 'required|exists:couriers,id',
            'orders.*.tracking_number' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        DB::beginTransaction();

        try {
            foreach ($request->orders as $orderData) {
                $order = Order::find($orderData['order_id']);
                
                if (!$order->tracking) {
                    $carrier = Courier::find($orderData['carrier_id']);
                    
                    OrderTracking::create([
                        'order_id' => $order->uuid,
                        'carrier_id' => $carrier->uuid,
                        'tracking_number' => $orderData['tracking_number'],
                        'status' => 'pending',
                        'tracking_url' => $carrier->getTrackingUrl($orderData['tracking_number']),
                    ]);
                    
                    $order->updateStatus('shipped', 'Bulk shipping update', auth()->user());
                    $successCount++;
                    
                    // Send notification
                    \App\Jobs\Notification\SendOrderShippedNotification::dispatch($order);
                } else {
                    $failureCount++;
                    $results[] = [
                        'order_id' => $order->id,
                        'error' => 'Tracking already exists',
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$successCount} orders updated, {$failureCount} failed",
                'data' => [
                    'success_count' => $successCount,
                    'failure_count' => $failureCount,
                    'details' => $results,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create tracking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tracking statistics
     */
    public function statistics(Request $request)
    {
        $query = OrderTracking::query();

        if ($request->has('carrier_id')) {
            $carrier = Courier::find($request->carrier_id);
            $query->where('carrier_id', $carrier?->uuid);
        }

        $stats = [
            'total' => $query->count(),
            'by_status' => [
                'pending' => (clone $query)->where('status', 'pending')->count(),
                'in_transit' => (clone $query)->where('status', 'in_transit')->count(),
                'out_for_delivery' => (clone $query)->where('status', 'out_for_delivery')->count(),
                'delivered' => (clone $query)->where('status', 'delivered')->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
            ],
            'on_time_delivery_rate' => $this->calculateOnTimeDeliveryRate(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Calculate on-time delivery rate
     */
    private function calculateOnTimeDeliveryRate(): float
    {
        $deliveredOrders = OrderTracking::where('status', 'delivered')
            ->whereNotNull('delivered_at')
            ->whereNotNull('estimated_delivery')
            ->get();

        if ($deliveredOrders->isEmpty()) {
            return 0;
        }

        $onTime = $deliveredOrders->filter(function($tracking) {
            return $tracking->delivered_at <= $tracking->estimated_delivery->endOfDay();
        })->count();

        return round(($onTime / $deliveredOrders->count()) * 100, 2);
    }
}
