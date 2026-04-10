<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderCreated;
use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateInventory implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [30, 60, 120];

    /**
     * Inventory service instance
     */
    protected $inventoryService;

    /**
     * Create the event listener.
     */
    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Handle the event.
     */
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        try {
            foreach ($order->items as $item) {
                // Deduct inventory for each product
                $this->inventoryService->deductStock(
                    $item->vendor_product_id,
                    $item->qty_ordered,
                    $order->vendor_store_id
                );

                // Check for low stock alert
                $this->checkLowStockAlert($item);
            }

            Log::info('Inventory updated for order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'items_processed' => $order->items->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update inventory for order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Check if product is low on stock and send alert
     */
    protected function checkLowStockAlert($orderItem): void
    {
        $product = $orderItem->vendorProduct;
        $currentStock = $this->inventoryService->getCurrentStock($product->id);
        $lowStockThreshold = $product->low_stock_threshold ?? 5;

        if ($currentStock <= $lowStockThreshold) {
            // Send alert to vendor
            $this->sendLowStockAlert($product, $currentStock);
        }
    }

    /**
     * Send low stock alert to vendor
     */
    protected function sendLowStockAlert($product, int $currentStock): void
    {
        $vendor = $product->vendor;
        $vendorUser = $vendor->user;

        if ($vendorUser) {
            // Create in-app notification
            $vendorUser->notifications()->create([
                'type' => 'low_stock_alert',
                'channel' => 'in_app',
                'title' => [
                    'en' => 'Low Stock Alert!',
                    'de' => 'Warnung: Niedriger Bestand!',
                    'fr' => 'Alerte: Stock faible!',
                ],
                'body' => [
                    'en' => "Product '{$product->name}' has only {$currentStock} units left in stock.",
                    'de' => "Produkt '{$product->name}' hat nur noch {$currentStock} Einheiten auf Lager.",
                    'fr' => "Le produit '{$product->name}' n'a plus que {$currentStock} unités en stock.",
                ],
                'data' => [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'current_stock' => $currentStock,
                ],
                'action_url' => '/vendor/products/' . $product->id,
                'priority' => 'medium',
            ]);

            // Send email for critical low stock
            if ($currentStock <= 2) {
                \Mail::to($vendorUser->email)->send(new \App\Mail\Inventory\LowStockAlert($product, $currentStock));
            }
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('UpdateInventory listener failed', [
            'order_id' => $this->order->id ?? null,
            'error' => $exception->getMessage(),
        ]);

        // Notify admin about inventory sync failure
        \App\Jobs\Notification\SendAdminAlert::dispatch(
            'Inventory Update Failed',
            'Failed to update inventory for order ID: ' . ($this->order->id ?? 'unknown')
        );
    }
}