<?php

namespace App\Jobs\Order;

use App\Models\Order\Order;
use App\Services\Order\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncOrderToLaravel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $magentoOrder;
    protected $isUpdate;

    public $tries = 3;
    public $backoff = [60, 300, 600]; // 1 min, 5 min, 10 min

    public function __construct(array $magentoOrder, bool $isUpdate = false)
    {
        $this->magentoOrder = $magentoOrder;
        $this->isUpdate = $isUpdate;
    }

    public function handle(OrderService $orderService): void
    {
        try {
            if ($this->isUpdate) {
                $existingOrder = Order::where('magento_order_id', $this->magentoOrder['entity_id'])->first();
                
                if ($existingOrder) {
                    $orderService->updateOrderFromMagento($existingOrder, $this->magentoOrder);
                }
            } else {
                $orderService->syncFromMagento($this->magentoOrder);
            }

            Log::info('Order synced successfully', [
                'magento_order_id' => $this->magentoOrder['entity_id'],
                'increment_id' => $this->magentoOrder['increment_id'],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to sync order', [
                'magento_order_id' => $this->magentoOrder['entity_id'],
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('Order sync job failed after retries', [
            'magento_order_id' => $this->magentoOrder['entity_id'],
            'error' => $exception->getMessage(),
        ]);

        // Notify admin about failed sync
        \App\Jobs\Notification\SendAdminAlert::dispatch(
            'Order Sync Failed',
            'Failed to sync order ' . $this->magentoOrder['increment_id'] . ': ' . $exception->getMessage()
        );
    }
}