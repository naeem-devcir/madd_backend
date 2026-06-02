<?php
// app/Services/Product/ProductService.php

namespace App\Services\Product;

use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\DB;

class ProductService
{
    protected $magentoService;

    public function __construct(MagentoService $magentoService)
    {
        $this->magentoService = $magentoService;
    }

    /**
     * Submit product draft for approval
     */
    public function submitForApproval(ProductDraft $draft): void
    {
        $draft->status = 'pending';
        $draft->save();

        // Create approval record
        ProductApproval::create([
            'product_draft_id' => $draft->id,
            'vendor_id' => $draft->vendor_id,
            'approval_type' => $draft->vendor_product_id ? 'update' : 'new',
            'submitted_data' => $draft->toArray(),
            'status' => 'pending',
        ]);

        // Notify admins
        \App\Jobs\Notification\SendProductApprovalNotification::dispatch($draft);
    }

    /**
     * Approve product draft and sync to Magento
     */
    public function approveProduct(ProductDraft $draft, int $adminId, ?string $notes = null): VendorProduct
    {
        DB::beginTransaction();

        try {
            $draft->loadMissing('vendor', 'store', 'product');

            // Update draft status
            $draft->status = 'approved';
            $draft->reviewed_by = $adminId;
            $draft->reviewed_at = now();
            $draft->review_notes = $notes;
            $draft->save();

            // Sync to Magento
            $magentoProduct = $this->magentoForVendor($draft->vendor)->createOrUpdateProduct($draft);

            // Create or update vendor product record
            if ($draft->vendor_product_id) {
                $product = $draft->product;
                $product->update([
                    'magento_product_id' => $magentoProduct['id'],
                    'magento_sku' => $magentoProduct['sku'],
                    'sku' => $draft->sku,
                    'name' => $draft->name,
                    'price' => $draft->price,
                    'quantity' => $draft->quantity,
                    'status' => 'active',
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                    'sync_errors' => null,
                    'metadata' => $this->mergedMetadata($product, $magentoProduct),
                ]);
            } else {
                $product = VendorProduct::create([
                    'vendor_id' => $draft->vendor_id,
                    'vendor_store_id' => $draft->vendor_store_id,
                    'magento_product_id' => $magentoProduct['id'],
                    'magento_sku' => $magentoProduct['sku'],
                    'sku' => $draft->sku,
                    'name' => $draft->name,
                    'type_id' => $magentoProduct['type_id'] ?? 'simple',
                    'attribute_set_id' => $magentoProduct['attribute_set_id'] ?? 4,
                    'price' => $draft->price,
                    'quantity' => $draft->quantity,
                    'status' => 'active',
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                    'metadata' => [
                        'magento' => $magentoProduct,
                        'created_by_admin_id' => $adminId,
                    ],
                ]);
                
                $draft->vendor_product_id = $product->id;
            }

            // $draft->magento_product_id = $magentoProduct['id'];
            $draft->published_at = now();
            $draft->save();

            // Update approval record
            $approval = ProductApproval::where('product_draft_id', $draft->id)->first();
            if ($approval) {
                $approval->status = 'approved';
                $approval->reviewed_by = $adminId;
                $approval->reviewed_at = now();
                $approval->admin_notes = $notes;
                $approval->save();
            }

            DB::commit();

            // Dispatch event
            event(new \App\Events\Product\ProductApproved($product, $draft));

            // Notify vendor
            \App\Jobs\Notification\SendProductApprovedNotification::dispatch($product);

            return $product;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reject product draft
     */
    public function rejectProduct(ProductDraft $draft, int $adminId, string $reason): void
    {
        DB::beginTransaction();

        try {
            $draft->status = 'rejected';
            $draft->rejection_reason = $reason;
            $draft->reviewed_by = $adminId;
            $draft->reviewed_at = now();
            $draft->save();

            $approval = ProductApproval::where('product_draft_id', $draft->id)->first();
            $approval->status = 'rejected';
            $approval->rejection_reason = $reason;
            $approval->reviewed_by = $adminId;
            $approval->reviewed_at = now();
            $approval->save();

            DB::commit();

            // Notify vendor
            \App\Jobs\Notification\SendProductRejectedNotification::dispatch($draft, $reason);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Request modification for product draft
     */
    public function createGroupedProduct(array $formData): array
    {
        DB::beginTransaction();

        try {
            $draft->status = 'needs_modification';
            $draft->review_notes = $notes;
            $draft->reviewed_by = $adminId;
            $draft->reviewed_at = now();
            $draft->save();

            $approval = ProductApproval::where('product_draft_id', $draft->id)->first();
            $approval->status = 'needs_modification';
            $approval->admin_notes = $notes;
            $approval->reviewed_by = $adminId;
            $approval->reviewed_at = now();
            $approval->save();

            DB::commit();

            // Notify vendor
            \App\Jobs\Notification\SendProductModificationRequestNotification::dispatch($draft, $notes);

        } catch (\Exception $e) {
            Log::error('Grouped Product Creation Failed: ' . $e->getMessage());
            throw new Exception('Grouped product creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create Bundle Product
     */
    public function createNewVersion(ProductDraft $draft): ProductDraft
    {
        $newDraft = $draft->replicate();
        $newDraft->version = $draft->version + 1;
        $newDraft->parent_draft_id = $draft->id;
        $newDraft->status = 'draft';
        $newDraft->reviewed_by = null;
        $newDraft->reviewed_at = null;
        $newDraft->rejection_reason = null;
        $newDraft->review_notes = null;
        $newDraft->save();

        return $newDraft;
    }

    /**
     * Get product validation rules
     */
    public function getValidationRules(Vendor $vendor): array
    {
        return [
            'sku' => 'required|string|max:255|unique:vendor_products,sku,NULL,id,vendor_id,' . $vendor->getKey(),
            'name' => 'required|string|max:500',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'weight' => 'nullable|numeric|min:0',
            'categories' => 'nullable|array',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|max:5120', // 5MB per image
        ];
    }

    public function createAdminProduct(array $data, int $adminId): VendorProduct
    {
        $vendor = Vendor::findOrFail($data['vendor_id']);
        $store = VendorStore::where('vendor_id', $vendor->getKey())->findOrFail($data['vendor_store_id']);

        $draft = new ProductDraft([
            'vendor_id' => $vendor->getKey(),
            'vendor_store_id' => $store->id,
            'sku' => $data['sku'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'short_description' => $data['short_description'] ?? null,
            'price' => $data['price'],
            'special_price' => $data['special_price'] ?? null,
            'special_price_from' => $data['special_price_from'] ?? null,
            'special_price_to' => $data['special_price_to'] ?? null,
            'quantity' => $data['quantity'] ?? 0,
            'weight' => $data['weight'] ?? 0,
            'product_data' => $data,
            'media_gallery' => $data['media_gallery'] ?? null,
            'categories' => $data['categories'] ?? null,
            'attributes' => $data['attributes'] ?? null,
            'seo_data' => $data['seo_data'] ?? null,
            'status' => 'approved',
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'published_at' => now(),
        ]);

        $draft->setRelation('vendor', $vendor);
        $draft->setRelation('store', $store);

        $magentoProduct = $this->magentoForVendor($vendor)->createOrUpdateProduct($draft);

        return DB::transaction(function () use ($draft, $magentoProduct, $adminId, $data) {
            $product = VendorProduct::create([
                'vendor_id' => $draft->vendor_id,
                'vendor_store_id' => $draft->vendor_store_id,
                'magento_product_id' => $magentoProduct['id'],
                'magento_sku' => $magentoProduct['sku'],
                'sku' => $draft->sku,
                'name' => $draft->name,
                'type_id' => $magentoProduct['type_id'] ?? 'simple',
                'attribute_set_id' => $magentoProduct['attribute_set_id'] ?? 4,
                'price' => $draft->price,
                'quantity' => $draft->quantity,
                'status' => $data['status'] ?? 'active',
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'metadata' => [
                    'magento' => $magentoProduct,
                    'created_by_admin_id' => $adminId,
                ],
            ]);

            $draft->vendor_product_id = $product->id;
            // $draft->magento_product_id = $magentoProduct['id'];
            $draft->save();

            return $product;
        });
    }

    public function updateAdminProduct(VendorProduct $product, array $data, int $adminId): VendorProduct
    {
        $product->loadMissing('vendor', 'store');

        $draft = new ProductDraft([
            'vendor_id' => $product->vendor_id,
            'vendor_store_id' => $product->vendor_store_id,
            'vendor_product_id' => $product->id,
            'sku' => $data['sku'] ?? $product->sku,
            'name' => $data['name'] ?? $product->name,
            'description' => $data['description'] ?? $product->draft?->description,
            'short_description' => $data['short_description'] ?? $product->draft?->short_description,
            'price' => $data['price'] ?? $product->price,
            'quantity' => $data['quantity'] ?? $product->quantity,
            'weight' => $data['weight'] ?? $product->draft?->weight ?? 0,
            'product_data' => $data,
            'media_gallery' => $data['media_gallery'] ?? $product->draft?->media_gallery,
            'categories' => $data['categories'] ?? $product->draft?->categories,
            'attributes' => $data['attributes'] ?? $product->draft?->attributes,
            'seo_data' => $data['seo_data'] ?? $product->draft?->seo_data,
            'status' => 'approved',
            // 'magento_product_id' => $product->magento_product_id,
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'published_at' => now(),
        ]);

        // $draft->magento_sku = $product->magento_sku;
        $draft->setRelation('vendor', $product->vendor);
        $draft->setRelation('store', $product->store);
        $draft->setRelation('product', $product);

        $magentoProduct = $this->magentoForVendor($product->vendor)->createOrUpdateProduct($draft);

        return DB::transaction(function () use ($product, $draft, $magentoProduct, $adminId, $data) {
            $product->update([
                'magento_product_id' => $magentoProduct['id'] ?? $product->magento_product_id,
                'magento_sku' => $magentoProduct['sku'] ?? $draft->sku,
                'sku' => $draft->sku,
                'name' => $draft->name,
                'type_id' => $magentoProduct['type_id'] ?? $product->type_id,
                'attribute_set_id' => $magentoProduct['attribute_set_id'] ?? $product->attribute_set_id,
                'price' => $draft->price,
                'quantity' => $draft->quantity,
                'status' => $data['status'] ?? $product->status,
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'sync_errors' => null,
                'metadata' => $this->mergedMetadata($product, $magentoProduct, ['updated_by_admin_id' => $adminId]),
            ]);

            $draft->vendor_product_id = $product->id;
            // $draft->magento_product_id = $product->magento_product_id;
            $draft->save();

            return $product->refresh();
        });
    }

    public function deleteAdminProduct(VendorProduct $product, int $adminId): array
    {
        $payload = [
            'link' => [
                'title' => $links[0]['title'] ?? 'Download',
                'price' => 0,
                'is_shareable' => 1,
                'sample' => [
                    'type' => 'file',
                ],
            ],
            'links' => array_map(function ($link, $index) {
                return [
                    'title' => $link['title'],
                    'sort_order' => $link['sort_order'] ?? $index,
                    'is_shareable' => $link['is_shareable'] ?? 1,
                    'price' => (float)($link['price'] ?? 0),
                    'number_of_downloads' => (int)($link['number_of_downloads'] ?? 0),
                    'link_type' => $link['link_type'] ?? 'file',
                    'link_file' => $link['link_type'] === 'file' ? ($link['link_file'] ?? '') : null,
                    'link_url' => $link['link_type'] === 'url' ? ($link['link_url'] ?? '') : null,
                    'sample_type' => $link['sample_type'] ?? 'file',
                    'sample_file' => $link['sample_type'] === 'file' ? ($link['sample_file'] ?? '') : null,
                    'sample_url' => $link['sample_type'] === 'url' ? ($link['sample_url'] ?? '') : null,
                ];
            }, $links, array_keys($links)),
        ];

        $this->magento->post("products/{$sku}/downloadable-links", $payload);
    }

    protected function addDownloadableSamples(string $sku, array $samples): void
    {
        $payload = [
            'samples' => array_map(function ($sample, $index) {
                return [
                    'title' => $sample['title'],
                    'sort_order' => $sample['sort_order'] ?? $index,
                    'sample_type' => $sample['sample_type'] ?? 'file',
                    'sample_file' => $sample['sample_type'] === 'file' ? ($sample['sample_file'] ?? '') : null,
                    'sample_url' => $sample['sample_type'] === 'url' ? ($sample['sample_url'] ?? '') : null,
                ];
            }, $samples, array_keys($samples)),
        ];

        $this->magento->post("products/{$sku}/downloadable-links/samples", $payload);
    }

    // ============ GIFT CARD HELPER METHODS ============

    protected function buildGiftCardPayload(array $data): array
    {
        $payload = [
            'product' => [
                'sku' => $data['sku'],
                'name' => $data['name'],
                'attribute_set_id' => (int)($data['attribute_set_id'] ?? 4),
                'status' => (int)($data['status'] ?? 1),
                'visibility' => (int)($data['visibility'] ?? 4),
                'type_id' => 'giftcard',
                'price' => (float)($data['price'] ?? 0),
                'extension_attributes' => [
                    'website_ids' => $data['website_ids'] ?? [1],
                ],
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        // Add gift card-specific custom attributes
        $giftCardAttributes = [];

        if (isset($data['giftcard_type'])) {
            $giftCardAttributes[] = [
                'attribute_code' => 'giftcard_type',
                'value' => $data['giftcard_type'],
            ];
        }

        if (isset($data['giftcard_amount_type'])) {
            $giftCardAttributes[] = [
                'attribute_code' => 'giftcard_amount_type',
                'value' => $data['giftcard_amount_type'],
            ];
        }

        if ($data['giftcard_amount_type'] === 'dynamic') {
            if (isset($data['giftcard_open_amount_min'])) {
                $giftCardAttributes[] = [
                    'attribute_code' => 'giftcard_open_amount_min',
                    'value' => (string) $data['giftcard_open_amount_min'],
                ];
            }
            if (isset($data['giftcard_open_amount_max'])) {
                $giftCardAttributes[] = [
                    'attribute_code' => 'giftcard_open_amount_max',
                    'value' => (string) $data['giftcard_open_amount_max'],
                ];
            }
        }

        if (isset($data['allow_message'])) {
            $giftCardAttributes[] = [
                'attribute_code' => 'allow_message',
                'value' => $data['allow_message'] ? '1' : '0',
            ];
        }

        if (isset($data['gift_message_max_length'])) {
            $giftCardAttributes[] = [
                'attribute_code' => 'gift_message_max_length',
                'value' => (string) $data['gift_message_max_length'],
            ];
        }

        $magentoResult = $this->magentoForVendor($product->vendor)->deleteProduct($product->magento_sku ?: $product->sku);

        DB::transaction(function () use ($product, $adminId, $magentoResult) {
            $product->update([
                'status' => 'deleted',
                'sync_status' => 'deleted',
                'last_synced_at' => now(),
                'sync_errors' => null,
                'metadata' => $this->mergedMetadata($product, $magentoResult, [
                    'deleted_by_admin_id' => $adminId,
                    'deleted_at' => now()->toIso8601String(),
                ]),
            ]);

            $product->delete();
        });

        return [
            'deleted' => true,
            'blocked' => false,
            'magento' => $magentoResult,
        ];
    }

    private function magentoForVendor(Vendor $vendor): MagentoService
    {
        return new MagentoService($vendor);
    }

    private function mergedMetadata(?VendorProduct $product, array $magentoProduct, array $extra = []): array
    {
        return array_merge($product?->metadata ?? [], $extra, [
            'magento' => $magentoProduct,
            'magento_synced_at' => now()->toIso8601String(),
        ]);
    }
}
