<?php

namespace App\Services\Product;

use App\Models\Product\ProductDraft;
use App\Models\Product\ProductApproval;
use App\Models\Product\VendorProduct;
use App\Models\Vendor\Vendor;
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
    public function approveProduct(ProductDraft $draft, string $adminId): void
    {
        DB::beginTransaction();

        try {
            // Update draft status
            $draft->status = 'approved';
            $draft->reviewed_by = $adminId;
            $draft->reviewed_at = now();
            $draft->save();

            // Sync to Magento
            $magentoProduct = $this->magentoService->createOrUpdateProduct($draft);

            // Create or update vendor product record
            if ($draft->vendor_product_id) {
                $product = $draft->product;
                $product->update([
                    'magento_product_id' => $magentoProduct['id'],
                    'magento_sku' => $magentoProduct['sku'],
                    'sku' => $draft->sku,
                    'name' => $draft->name,
                    'status' => 'active',
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                ]);
            } else {
                $product = VendorProduct::create([
                    'vendor_id' => $draft->vendor_id,
                    'vendor_store_id' => $draft->vendor_store_id,
                    'magento_product_id' => $magentoProduct['id'],
                    'magento_sku' => $magentoProduct['sku'],
                    'sku' => $draft->sku,
                    'name' => $draft->name,
                    'status' => 'active',
                    'sync_status' => 'synced',
                    'last_synced_at' => now(),
                ]);
                
                $draft->vendor_product_id = $product->id;
                $draft->save();
            }

            // Update approval record
            $approval = ProductApproval::where('product_draft_id', $draft->id)->first();
            $approval->status = 'approved';
            $approval->reviewed_by = $adminId;
            $approval->reviewed_at = now();
            $approval->save();

            DB::commit();

            // Dispatch event
            event(new \App\Events\Product\ProductApproved($product, $draft));

            // Notify vendor
            \App\Jobs\Notification\SendProductApprovedNotification::dispatch($product);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Reject product draft
     */
    public function rejectProduct(ProductDraft $draft, string $adminId, string $reason): void
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
    public function requestModification(ProductDraft $draft, string $adminId, string $notes): void
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
}
