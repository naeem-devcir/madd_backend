<?php

namespace App\Jobs\Jobs\Product;

use App\Models\Product\ProductDraft;
use App\Models\Product\VendorProduct;
use App\Services\Integration\MagentoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProductToMagento implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $product;
    protected $isDraft;

    public $tries = 3;
    public $backoff = [60, 300, 900]; // 1 min, 5 min, 15 min
    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     *
     * @param VendorProduct|ProductDraft $product
     * @param bool $isDraft
     */
    public function __construct($product, bool $isDraft = true)
    {
        $this->product = $product;
        $this->isDraft = $isDraft;
    }

    /**
     * Execute the job.
     */
    public function handle(MagentoService $magentoService): void
    {
        try {
            if ($this->isDraft) {
                $draft = $this->product;
                $result = $magentoService->createOrUpdateProduct($draft);
                
                if ($result['success']) {
                    // Update or create vendor product record
                    if ($draft->vendor_product_id) {
                        $vendorProduct = $draft->product;
                        $vendorProduct->update([
                            'magento_product_id' => $result['product_id'],
                            'magento_sku' => $result['sku'],
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                        ]);
                    } else {
                        $vendorProduct = VendorProduct::create([
                            'vendor_id' => $draft->vendor_id,
                            'vendor_store_id' => $draft->vendor_store_id,
                            'magento_product_id' => $result['product_id'],
                            'magento_sku' => $result['sku'],
                            'sku' => $draft->sku,
                            'name' => $draft->name,
                            'status' => 'active',
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                        ]);
                        $draft->vendor_product_id = $vendorProduct->id;
                        $draft->save();
                    }
                    
                    Log::info('Product synced to Magento', [
                        'draft_id' => $draft->id,
                        'product_id' => $result['product_id'],
                        'sku' => $result['sku'],
                    ]);
                } else {
                    throw new \Exception($result['error'] ?? 'Failed to sync product to Magento');
                }
            } else {
                $vendorProduct = $this->product;
                $result = $magentoService->updateProduct($vendorProduct);
                
                if ($result['success']) {
                    $vendorProduct->update([
                        'sync_status' => 'synced',
                        'last_synced_at' => now(),
                    ]);
                    
                    Log::info('Product updated in Magento', [
                        'product_id' => $vendorProduct->id,
                        'sku' => $vendorProduct->sku,
                    ]);
                } else {
                    throw new \Exception($result['error'] ?? 'Failed to update product in Magento');
                }
            }

        } catch (\Exception $e) {
            // Mark sync as failed
            if ($this->isDraft) {
                $this->product->update([
                    'sync_status' => 'failed',
                    'sync_errors' => $e->getMessage(),
                ]);
            } else {
                $this->product->update([
                    'sync_status' => 'failed',
                    'sync_errors' => $e->getMessage(),
                ]);
            }

            Log::error('Failed to sync product to Magento', [
                'product_type' => $this->isDraft ? 'draft' : 'product',
                'product_id' => $this->product->id,
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
        Log::critical('Product sync job failed after retries', [
            'product_type' => $this->isDraft ? 'draft' : 'product',
            'product_id' => $this->product->id,
            'error' => $exception->getMessage(),
        ]);

        // Notify admin about failed sync
        \App\Jobs\Jobs\Notification\SendAdminAlert::dispatch(
            'Product Sync Failed',
            'Failed to sync product ID: ' . $this->product->id . ' to Magento. Error: ' . $exception->getMessage()
        );
    }
}