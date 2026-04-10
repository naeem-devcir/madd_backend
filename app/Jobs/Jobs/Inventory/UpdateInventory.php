<?php

namespace App\Jobs\Jobs\Inventory;

use App\Models\Product\VendorProduct;
use App\Services\Integration\MagentoService;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateInventory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $productId;
    protected $quantity;
    protected $operation;

    public $tries = 3;
    public $backoff = [30, 60, 120]; // 30 sec, 1 min, 2 min

    /**
     * Create a new job instance.
     *
     * @param string $productId
     * @param int $quantity
     * @param string $operation (set, increment, decrement)
     */
    public function __construct(string $productId, int $quantity, string $operation = 'set')
    {
        $this->productId = $productId;
        $this->quantity = $quantity;
        $this->operation = $operation;
    }

    /**
     * Execute the job.
     */
    public function handle(MagentoService $magentoService, NotificationService $notificationService): void
    {
        try {
            $product = VendorProduct::findOrFail($this->productId);

            // Get current stock from Magento
            $currentStock = $magentoService->getProductStock($product->magento_sku);

            // Calculate new quantity based on operation
            $newQuantity = match($this->operation) {
                'increment' => $currentStock + $this->quantity,
                'decrement' => max(0, $currentStock - $this->quantity),
                default => $this->quantity,
            };

            // Update inventory in Magento
            $result = $magentoService->updateProductStock($product->magento_sku, $newQuantity);

            if ($result['success']) {
                Log::info('Inventory updated successfully', [
                    'product_id' => $this->productId,
                    'sku' => $product->magento_sku,
                    'old_quantity' => $currentStock,
                    'new_quantity' => $newQuantity,
                    'operation' => $this->operation,
                ]);

                // Check for low stock alert
                if ($newQuantity <= 5 && $newQuantity > 0) {
                    LowStockAlert::dispatch($product, $newQuantity);
                }

                // Check for out of stock
                if ($newQuantity == 0 && $currentStock > 0) {
                    $notificationService->send($product->vendor->user, [
                        'type' => 'out_of_stock',
                        'title' => 'Product Out of Stock',
                        'body' => 'Your product "' . $product->name . '" (SKU: ' . $product->sku . ') is now out of stock.',
                        'data' => [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'sku' => $product->sku,
                        ],
                        'channels' => ['email', 'in_app'],
                    ]);
                }
            } else {
                throw new \Exception($result['error'] ?? 'Failed to update inventory');
            }

        } catch (\Exception $e) {
            Log::error('Failed to update inventory', [
                'product_id' => $this->productId,
                'quantity' => $this->quantity,
                'operation' => $this->operation,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Inventory update job failed after retries', [
            'product_id' => $this->productId,
            'error' => $exception->getMessage(),
        ]);

        // Notify admin about failed inventory sync
        \App\Jobs\Jobs\Notification\SendAdminAlert::dispatch(
            'Inventory Sync Failed',
            'Failed to update inventory for product ID: ' . $this->productId . '. Error: ' . $exception->getMessage()
        );
    }
}