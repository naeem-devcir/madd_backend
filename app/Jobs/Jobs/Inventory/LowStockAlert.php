<?php

namespace App\Jobs\Jobs\Inventory;

use App\Models\Product\VendorProduct;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LowStockAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $product;
    protected $currentStock;

    public $tries = 2;
    public $backoff = [60, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(VendorProduct $product, int $currentStock)
    {
        $this->product = $product;
        $this->currentStock = $currentStock;
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            $vendor = $this->product->vendor;
            $threshold = $vendor->plan->getFeatureValue('low_stock_threshold', 5);

            // Only send alert if below threshold
            if ($this->currentStock <= $threshold) {
                // Send notification to vendor
                $notificationService->send($vendor->user, [
                    'type' => 'low_stock',
                    'title' => 'Low Stock Alert',
                    'body' => 'Your product "' . $this->product->name . '" (SKU: ' . $this->product->sku . ') has only ' . $this->currentStock . ' units left in stock.',
                    'priority' => 'high',
                    'data' => [
                        'product_id' => $this->product->id,
                        'product_name' => $this->product->name,
                        'sku' => $this->product->sku,
                        'current_stock' => $this->currentStock,
                        'threshold' => $threshold,
                    ],
                    'channels' => ['email', 'in_app', 'sms'],
                ]);

                // Also send to admin if stock is critically low
                if ($this->currentStock <= 2) {
                    $this->notifyAdmin($notificationService);
                }

                Log::info('Low stock alert sent', [
                    'product_id' => $this->product->id,
                    'sku' => $this->product->sku,
                    'current_stock' => $this->currentStock,
                    'threshold' => $threshold,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to send low stock alert', [
                'product_id' => $this->product->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Notify admin about critical low stock.
     */
    protected function notifyAdmin(NotificationService $notificationService): void
    {
        $admins = \App\Models\User::role('admin')->get();

        foreach ($admins as $admin) {
            $notificationService->send($admin, [
                'type' => 'critical_low_stock',
                'title' => 'Critical Low Stock Alert',
                'body' => 'Vendor "' . $this->product->vendor->company_name . '" has a critically low stock for product "' . $this->product->name . '" (Only ' . $this->currentStock . ' units left).',
                'priority' => 'urgent',
                'data' => [
                    'vendor_id' => $this->product->vendor->id,
                    'vendor_name' => $this->product->vendor->company_name,
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->name,
                    'current_stock' => $this->currentStock,
                ],
                'channels' => ['email', 'in_app'],
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Low stock alert job failed', [
            'product_id' => $this->product->id,
            'error' => $exception->getMessage(),
        ]);
    }
}