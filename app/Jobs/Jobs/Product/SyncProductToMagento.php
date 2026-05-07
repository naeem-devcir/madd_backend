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
                $result = $draft->vendor
                    ? $draft->vendor->getMagentoService()->createOrUpdateProduct($draft)
                    : $magentoService->createOrUpdateProduct($draft);
                
                if (! empty($result['id']) && ! empty($result['sku'])) {
                    // Update or create vendor product record
                    if ($draft->vendor_product_id) {
                        $vendorProduct = $draft->product;
                        $vendorProduct->update([
                            'magento_product_id' => $result['id'],
                            'magento_sku' => $result['sku'],
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                            'sync_errors' => null,
                        ]);
                    } else {
                        $vendorProduct = VendorProduct::create([
                            'vendor_id' => $draft->vendor_id,
                            'vendor_store_id' => $draft->vendor_store_id,
                            'magento_product_id' => $result['id'],
                            'magento_sku' => $result['sku'],
                            'sku' => $draft->sku,
                            'name' => $draft->name,
                            'type_id' => $result['type_id'] ?? 'simple',
                            'attribute_set_id' => $result['attribute_set_id'] ?? 4,
                            'price' => $draft->price,
                            'quantity' => $draft->quantity,
                            'status' => 'active',
                            'sync_status' => 'synced',
                            'last_synced_at' => now(),
                            'metadata' => ['magento' => $result],
                        ]);
                        $draft->vendor_product_id = $vendorProduct->id;
                        $draft->magento_product_id = $result['id'];
                        $draft->save();
                    }
                    
                    Log::info('Product synced to Magento', [
                        'draft_id' => $draft->id,
                        'product_id' => $result['id'],
                        'sku' => $result['sku'],
                    ]);
                } else {
                    throw new \Exception('Failed to sync product to Magento');
                }
            } else {
                $vendorProduct = $this->product;
                $result = $vendorProduct->vendor
                    ? $vendorProduct->vendor->getMagentoService()->createOrUpdateProduct($vendorProduct)
                    : $magentoService->createOrUpdateProduct($vendorProduct);
                
                if (! empty($result['id']) && ! empty($result['sku'])) {
                    $vendorProduct->update([
                        'magento_product_id' => $result['id'],
                        'magento_sku' => $result['sku'],
                        'sync_status' => 'synced',
                        'last_synced_at' => now(),
                        'sync_errors' => null,
                    ]);
                    
                    Log::info('Product updated in Magento', [
                        'product_id' => $vendorProduct->id,
                        'sku' => $vendorProduct->sku,
                    ]);
                } else {
                    throw new \Exception('Failed to update product in Magento');
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
