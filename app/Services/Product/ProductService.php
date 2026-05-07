<?php

namespace App\Services\Product;

use App\Models\Product\ProductDraft;
use App\Models\Product\ProductApproval;
use App\Models\Product\VendorProduct;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
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
    public function requestModification(ProductDraft $draft, int $adminId, string $notes): void
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
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Create new version of product draft
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
        $product->loadMissing('vendor');

        if ($product->orderItems()->exists()) {
            return [
                'deleted' => false,
                'blocked' => true,
                'reason' => 'Cannot delete product with existing orders',
                'order_count' => $product->orderItems()->count(),
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