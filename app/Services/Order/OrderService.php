<?php

namespace App\Services\Order;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Vendor\Vendor;
use App\Models\User;
use App\Services\Vendor\CommissionService;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;

class OrderService
{
    protected $commissionService;
    protected $inventoryService;

    public function __construct(CommissionService $commissionService, InventoryService $inventoryService)
    {
        $this->commissionService = $commissionService;
        $this->inventoryService = $inventoryService;
    }

    /**
     * Sync order from Magento
     */
    public function syncFromMagento(array $magentoOrder): Order
    {
        // Check if order already exists
        $existingOrder = Order::where('magento_order_id', $magentoOrder['entity_id'])->first();
        
        if ($existingOrder) {
            return $this->updateOrderFromMagento($existingOrder, $magentoOrder);
        }

        DB::beginTransaction();

        try {
            // Find vendor by store ID
            $vendorStore = VendorStore::where('magento_store_id', $magentoOrder['store_id'])->first();
            
            if (!$vendorStore) {
                throw new \Exception('Vendor store not found for Magento store ID: ' . $magentoOrder['store_id']);
            }

            // Find or create customer
            $customer = null;
            if (isset($magentoOrder['customer_id'])) {
                $customer = User::where('magento_customer_id', $magentoOrder['customer_id'])->first();
            }

            // Create order
            $order = Order::create([
                'magento_order_id' => $magentoOrder['entity_id'],
                'magento_order_increment_id' => $magentoOrder['increment_id'],
                'vendor_id' => $vendorStore->vendor_id,
                'vendor_store_id' => $vendorStore->uuid,
                'customer_id' => $customer?->uuid,
                'customer_email' => $magentoOrder['customer_email'],
                'customer_firstname' => $magentoOrder['customer_firstname'],
                'customer_lastname' => $magentoOrder['customer_lastname'],
                'status' => $magentoOrder['status'],
                'currency_code' => $magentoOrder['order_currency_code'],
                'subtotal' => $magentoOrder['subtotal'],
                'tax_amount' => $magentoOrder['tax_amount'],
                'shipping_amount' => $magentoOrder['shipping_amount'],
                'discount_amount' => $magentoOrder['discount_amount'],
                'grand_total' => $magentoOrder['grand_total'],
                'payment_method' => $magentoOrder['payment']['method'],
                'shipping_method' => $magentoOrder['shipping_method'] ?? null,
                'shipping_address' => json_encode($magentoOrder['shipping_address'] ?? []),
                'billing_address' => json_encode($magentoOrder['billing_address'] ?? []),
                'synced_at' => now(),
            ]);

            // Create order items
            foreach ($magentoOrder['items'] as $item) {
                $vendorProduct = VendorProduct::where('magento_sku', $item['sku'])->first();

                OrderItem::create([
                    'order_id' => $order->uuid,
                    'magento_item_id' => $item['item_id'],
                    'vendor_product_id' => $vendorProduct?->id,
                    'magento_product_id' => $item['product_id'],
                    'product_sku' => $item['sku'],
                    'product_name' => $item['name'],
                    'qty_ordered' => $item['qty_ordered'],
                    'price' => $item['price'],
                    'tax_amount' => $item['tax_amount'],
                    'row_total' => $item['row_total'],
                ]);
            }

            // Calculate commission
            $this->commissionService->calculateOrderCommission($order);

            DB::commit();

            // Dispatch events
            event(new \App\Events\Order\OrderCreated($order));

            return $order;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update existing order from Magento
     */
    public function updateOrderFromMagento(Order $order, array $magentoOrder): Order
    {
        $oldStatus = $order->status;
        
        $order->update([
            'status' => $magentoOrder['status'],
            'synced_at' => now(),
        ]);

        if ($oldStatus !== $order->status) {
            event(new \App\Events\Order\OrderStatusChanged($order, $oldStatus, $order->status));
        }

        return $order;
    }

    /**
     * Process order cancellation
     */
    public function cancelOrder(Order $order, string $reason, ?User $user = null): void
    {
        if (!$order->canBeCancelled()) {
            throw new \Exception('Order cannot be cancelled at this stage');
        }

        DB::beginTransaction();

        try {
            // Restore inventory
            foreach ($order->items as $item) {
                $this->inventoryService->restoreStock($item->vendor_product_id, $item->qty_ordered);
            }

            // Update order status
            $order->updateStatus('cancelled', $reason, $user);

            // Process refund if payment was captured
            if ($order->payment_status === 'paid') {
                $this->processRefund($order);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Process refund for order
     */
    public function processRefund(Order $order): void
    {
        // This would call payment gateway API
        // For now, mark as refunded
        $order->updatePaymentStatus('refunded', 'Order cancelled - full refund');
    }

    /**
     * Get order timeline
     */
    public function getOrderTimeline(Order $order): array
    {
        $timeline = [];

        // Order created
        $timeline[] = [
            'event' => 'Order Created',
            'status' => 'pending',
            'description' => 'Order has been placed',
            'timestamp' => $order->created_at->toIso8601String(),
        ];

        // Payment received
        if ($order->payment_status === 'paid') {
            $timeline[] = [
                'event' => 'Payment Received',
                'status' => 'paid',
                'description' => 'Payment has been confirmed',
                'timestamp' => $order->updated_at->toIso8601String(),
            ];
        }

        // Order processed
        if ($order->status === 'processing') {
            $timeline[] = [
                'event' => 'Order Processing',
                'status' => 'processing',
                'description' => 'Order is being prepared',
                'timestamp' => $order->updated_at->toIso8601String(),
            ];
        }

        // Order shipped
        if ($order->shipped_at) {
            $timeline[] = [
                'event' => 'Order Shipped',
                'status' => 'shipped',
                'description' => 'Order has been shipped' . ($order->tracking_number ? ' - Tracking: ' . $order->tracking_number : ''),
                'timestamp' => $order->shipped_at->toIso8601String(),
            ];
        }

        // Order delivered
        if ($order->delivered_at) {
            $timeline[] = [
                'event' => 'Order Delivered',
                'status' => 'delivered',
                'description' => 'Order has been delivered',
                'timestamp' => $order->delivered_at->toIso8601String(),
            ];
        }

        return $timeline;
    }

    /**
     * Get order statistics for vendor
     */
    public function getVendorOrderStats(Vendor $vendor, string $period = 'month'): array
    {
        $startDate = match($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        $orders = $vendor->orders()
            ->where('created_at', '>=', $startDate)
            ->get();

        return [
            'total_orders' => $orders->count(),
            'total_revenue' => $orders->sum('grand_total'),
            'average_order_value' => $orders->avg('grand_total') ?? 0,
            'orders_by_status' => $orders->groupBy('status')->map->count(),
            'orders_by_day' => $orders->groupBy(function($order) {
                return $order->created_at->format('Y-m-d');
            })->map->count(),
        ];
    }
}
