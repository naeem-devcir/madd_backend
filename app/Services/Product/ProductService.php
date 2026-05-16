<?php

namespace App\Services\Product;

use App\Models\Product\ProductDraft;
use App\Models\Product\ProductApproval;
use App\Models\Product\VendorProduct;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Services\Integration\MagentoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ProductService
{
    /**
     * Get Magento service instance for a specific vendor
     */
    private function getMagentoServiceForVendor(Vendor $vendor): MagentoService
    {
        return MagentoService::forVendor($vendor);
    }

    /**
     * Submit product draft for approval
     */
    public function submitForApproval(ProductDraft $draft): void
    {
        $draft->status = 'pending';
        $draft->save();

        ProductApproval::create([
            'product_draft_id' => $draft->id,
            'vendor_id' => $draft->vendor_id,
            'approval_type' => $draft->vendor_product_id ? 'update' : 'new',
            'submitted_data' => $draft->toArray(),
            'status' => 'pending',
        ]);

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

            $draft->status = 'approved';
            $draft->reviewed_by = $adminId;
            $draft->reviewed_at = now();
            $draft->review_notes = $notes;
            $draft->save();

            $magentoProduct = $this->createOrUpdateProductInMagento($draft);

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

            $draft->published_at = now();
            $draft->save();

            $approval = ProductApproval::where('product_draft_id', $draft->id)->first();
            if ($approval) {
                $approval->status = 'approved';
                $approval->reviewed_by = $adminId;
                $approval->reviewed_at = now();
                $approval->admin_notes = $notes;
                $approval->save();
            }

            DB::commit();

            event(new \App\Events\Product\ProductApproved($product, $draft));
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
            'images.*' => 'image|max:5120',
        ];
    }

    /**
     * Create product directly by admin (bypasses approval)
     */
    public function createAdminProduct(array $data, int $adminId): VendorProduct
    {
        $vendor = Vendor::findOrFail($data['vendor_id']);
        $store = VendorStore::where('vendor_id', $vendor->getKey())->findOrFail($data['vendor_store_id']);
        $productData = $this->mergeProductData($data);

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
            'product_data' => $productData,
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

        // REMOVE these lines - they don't exist in product_drafts table:
        // $draft->type_id = $data['type_id'] ?? 'simple';
        // $draft->attribute_set_id = $data['attribute_set_id'] ?? 4;
        // $draft->visibility = $data['visibility'] ?? 4;

        $magentoProduct = $this->createOrUpdateProductInMagento($draft);

        return DB::transaction(function () use ($draft, $magentoProduct, $adminId, $data, $productData) {
            // Get Magento-specific fields from the merged payload.
            $typeId = $productData['type_id'] ?? 'simple';
            $attributeSetId = $productData['attribute_set_id'] ?? 4;

            $product = VendorProduct::create([
                'vendor_id' => $draft->vendor_id,
                'vendor_store_id' => $draft->vendor_store_id,
                'magento_product_id' => $magentoProduct['id'],
                'magento_sku' => $magentoProduct['sku'],
                'sku' => $draft->sku,
                'name' => $draft->name,
                'type_id' => $magentoProduct['type_id'] ?? $typeId,
                'attribute_set_id' => $magentoProduct['attribute_set_id'] ?? $attributeSetId,
                'price' => $draft->price,
                'quantity' => $draft->quantity,
                'status' => $data['status'] ?? 'active',
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'metadata' => [
                    'magento' => $magentoProduct,
                    'created_by_admin_id' => $adminId,
                    'full_payload' => $productData,
                ],
            ]);

            $draft->vendor_product_id = $product->id;
            $draft->save();

            return $product;
        });
    }

    /**
     * Update product directly by admin (bypasses approval)
     */
    public function updateAdminProduct(VendorProduct $product, array $data, int $adminId): VendorProduct
    {
        $product->loadMissing('vendor', 'store');

        $productData = $this->mergeProductData($data, $product);

        $draft = new ProductDraft([
            'vendor_id' => $product->vendor_id,
            'vendor_store_id' => $product->vendor_store_id,
            'vendor_product_id' => $product->id,
            'magento_product_id' => $product->magento_product_id,
            'sku' => $data['sku'] ?? $product->sku,
            'name' => $data['name'] ?? $product->name,
            'description' => $data['description'] ?? $product->draft?->description,
            'short_description' => $data['short_description'] ?? $product->draft?->short_description,
            'price' => $data['price'] ?? $product->price,
            'special_price' => $data['special_price'] ?? $product->draft?->special_price,
            'special_price_from' => $data['special_price_from'] ?? $product->draft?->special_price_from,
            'special_price_to' => $data['special_price_to'] ?? $product->draft?->special_price_to,
            'quantity' => $data['quantity'] ?? $product->quantity,
            'weight' => $data['weight'] ?? $product->draft?->weight ?? 0,
            'product_data' => $productData,
            'media_gallery' => $data['media_gallery'] ?? $product->draft?->media_gallery,
            'categories' => $data['categories'] ?? $product->draft?->categories,
            'attributes' => $data['attributes'] ?? $product->draft?->attributes,
            'seo_data' => $data['seo_data'] ?? $product->draft?->seo_data,
            'status' => 'approved',
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'published_at' => now(),
        ]);

        $draft->setRelation('vendor', $product->vendor);
        $draft->setRelation('store', $product->store);
        $draft->setRelation('product', $product);

        $magentoProduct = $this->createOrUpdateProductInMagento($draft);

        return DB::transaction(function () use ($product, $draft, $magentoProduct, $adminId, $data, $productData) {
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
                'metadata' => $this->mergedMetadata($product, $magentoProduct, [
                    'updated_by_admin_id' => $adminId,
                    'full_payload' => $productData,
                ]),
            ]);

            $draft->vendor_product_id = $product->id;
            $draft->save();

            return $product->refresh();
        });
    }

    /**
     * Delete product from admin
     */
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

        $magentoResult = $this->deleteProductFromMagento($product);

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

    /**
     * Sync product stock to Magento
     */
    public function syncStock(VendorProduct $product): array
    {
        $vendor = $product->vendor;
        if (!$vendor || !$vendor->id) {
            throw new \Exception('Product has no associated vendor');
        }

        $magentoService = $this->getMagentoServiceForVendor($vendor);
        $sku = $product->magento_sku ?: $product->sku;

        return $magentoService->put('products/' . rawurlencode($sku) . '/stockItems/1', [
            'stockItem' => [
                'qty' => (int) $product->quantity,
                'is_in_stock' => $product->quantity > 0
            ]
        ]);
    }

    /**
     * Get products from Magento with filters
     */
    public function getProductsFromMagento(Vendor $vendor, array $filters = [], int $page = 1, int $size = 20): array
    {
        $magentoService = $this->getMagentoServiceForVendor($vendor);

        $params = [
            'searchCriteria[currentPage]' => $page,
            'searchCriteria[pageSize]' => $size,
        ];

        if (!empty($filters['category_id'])) {
            $params['searchCriteria[filterGroups][0][filters][0][field]'] = 'category_id';
            $params['searchCriteria[filterGroups][0][filters][0][value]'] = $filters['category_id'];
            $params['searchCriteria[filterGroups][0][filters][0][conditionType]'] = 'eq';
        }

        if (!empty($filters['price_from'])) {
            $params['searchCriteria[filterGroups][1][filters][0][field]'] = 'price';
            $params['searchCriteria[filterGroups][1][filters][0][value]'] = $filters['price_from'];
            $params['searchCriteria[filterGroups][1][filters][0][conditionType]'] = 'gteq';
        }

        if (!empty($filters['price_to'])) {
            $params['searchCriteria[filterGroups][2][filters][0][field]'] = 'price';
            $params['searchCriteria[filterGroups][2][filters][0][value]'] = $filters['price_to'];
            $params['searchCriteria[filterGroups][2][filters][0][conditionType]'] = 'lteq';
        }

        return $magentoService->get('products', $params);
    }

    /**
     * Get single product from Magento by SKU
     */
    public function getProductFromMagento(Vendor $vendor, string $sku): array
    {
        $magentoService = $this->getMagentoServiceForVendor($vendor);
        return $magentoService->get('products/' . rawurlencode($sku));
    }

    // ─────────────────────────────────────────────────────
    // PRIVATE HELPER METHODS
    // ─────────────────────────────────────────────────────

    /**
     * Create or update product in Magento
     */
    private function createOrUpdateProductInMagento(ProductDraft|VendorProduct $product): array
    {
        // Get the vendor from the product
        $vendor = $product->vendor;
        if (!$vendor || !$vendor->id) {
            throw new \Exception('Product has no associated vendor');
        }

        // Create service for this specific vendor
        $magentoService = $this->getMagentoServiceForVendor($vendor);

        $payload = $this->buildProductPayload($product);
        $existingProduct = $product instanceof ProductDraft ? $product->product : null;
        $magentoSku = $product->magento_sku
            ?? $existingProduct?->magento_sku
            ?? null;
        $magentoProductId = $product->magento_product_id
            ?? $existingProduct?->magento_product_id
            ?? null;

        if (!empty($magentoProductId) || !empty($magentoSku)) {
            return $magentoService->put(
                'products/' . rawurlencode($magentoSku ?: $product->sku),
                ['product' => $payload]
            );
        }

        return $magentoService->post('products', ['product' => $payload]);
    }
    /**
     * Sanitize image filename for Magento
     */
    /**
     * Sanitize image filename for Magento
     */
    private function sanitizeImageName(string $filename): string
    {
        // Get extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $extension = $extension ?: 'jpg';

        // Remove any non-alphanumeric characters except dot, dash, and underscore
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        // Allow letters, numbers, dashes, underscores, and dots
        $basename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $basename);

        // Limit length to 30 characters
        $basename = substr($basename, 0, 30);

        // If basename is empty, generate a random name
        if (empty($basename)) {
            $basename = 'image_' . time() . '_' . bin2hex(random_bytes(4));
        }

        // Ensure the name doesn't start or end with special characters
        $basename = trim($basename, '.-_');

        // If still empty, use timestamp
        if (empty($basename)) {
            $basename = (string) time();
        }

        return $basename . '.' . $extension;
    }
    /**
     * Delete product from Magento
     */
    private function deleteProductFromMagento(VendorProduct $product): array
    {
        $vendor = $product->vendor;
        if (!$vendor || !$vendor->id) {
            throw new \Exception('Product has no associated vendor');
        }

        $magentoService = $this->getMagentoServiceForVendor($vendor);
        $sku = $product->magento_sku ?: $product->sku;

        return $magentoService->delete('products/' . rawurlencode($sku));
    }

    /**
     * Merge Magento-specific payload fields from top-level and product_data.
     */
    private function mergeProductData(array $data, ?VendorProduct $existingProduct = null): array
    {
        $existingPayload = data_get($existingProduct?->metadata, 'full_payload', []);
        $nestedPayload = $data['product_data'] ?? [];
        $topLevelPayload = $data;
        unset($topLevelPayload['product_data']);

        $merged = array_replace_recursive($existingPayload, $nestedPayload, $topLevelPayload);

        if ($existingProduct) {
            $merged['type_id'] = $merged['type_id'] ?? $existingProduct->type_id ?? 'simple';
            $merged['attribute_set_id'] = $merged['attribute_set_id'] ?? $existingProduct->attribute_set_id ?? 4;
            $merged['status'] = $merged['status'] ?? $existingProduct->status ?? 'active';
        }

        return $merged;
    }

    /**
     * Build product payload for Magento API
     */
    private function buildProductPayload(ProductDraft|VendorProduct $product): array
    {
        $vendor = $product->vendor;
        if (!$vendor || !$vendor->id) {
            throw new \Exception('Cannot build product payload: No vendor associated');
        }

        // Get the full product data from product_data JSON field
        $linkedProduct = $product instanceof ProductDraft ? $product->product : null;
        $productData = $product instanceof ProductDraft
            ? $this->mergeProductData($product->product_data ?? [], $linkedProduct)
            : $this->mergeProductData($product->metadata['full_payload'] ?? [], $product);

        $attributes = is_array($product->attributes ?? null) ? $product->attributes : [];

        // Get Magento-specific fields from product_data
        $typeId = $productData['type_id'] ?? $product->type_id ?? $linkedProduct?->type_id ?? 'simple';
        $attributeSetId = $productData['attribute_set_id'] ?? $product->attribute_set_id ?? $linkedProduct?->attribute_set_id ?? 4;
        $visibility = $productData['visibility'] ?? 4;

        $requestedStatus = $product instanceof ProductDraft
            ? data_get($productData, 'status', $linkedProduct?->status ?? 'active')
            : ($product->status ?? 'active');

        $payload = [
            'sku' => $product->sku,
            'name' => $product->name,
            'attribute_set_id' => (int) $attributeSetId,
            'price' => (float) ($product->price ?? 0),
            'status' => $requestedStatus === 'inactive' ? 2 : 1,
            'visibility' => (int) $visibility,
            'type_id' => $typeId,
            'weight' => (float) ($product->weight ?? 0),
            'extension_attributes' => $this->buildExtensionAttributes($product, $vendor, $productData),
            'product_links' => $productData['product_links'] ?? [],
            'options' => $productData['options'] ?? [],
            'media_gallery_entries' => $this->buildMediaGalleryEntries($product, $productData),
            'tier_prices' => $productData['tier_prices'] ?? [],
            'custom_attributes' => $this->buildCustomAttributes($product, $vendor, $attributes),
        ];

        // Add created_at and updated_at if they exist in product_data
        if (isset($productData['created_at'])) {
            $payload['created_at'] = $productData['created_at'];
        }

        if (isset($productData['updated_at'])) {
            $payload['updated_at'] = $productData['updated_at'];
        }

        return array_filter($payload, fn($value) => $value !== null);
    }


    private function buildCustomAttributes(ProductDraft|VendorProduct $product, Vendor $vendor, array $attributes): array
    {
        $customAttributes = [
            ['attribute_code' => 'description', 'value' => $product->description ?? $attributes['description'] ?? ''],
            ['attribute_code' => 'short_description', 'value' => $product->short_description ?? $attributes['short_description'] ?? ''],
        ];

        $skipCodes = ['description', 'short_description'];

        foreach ($attributes as $code => $value) {
            if (in_array($code, $skipCodes, true)) {
                continue;
            }

            if (is_array($value)) {
                $resolvedValues = array_map(
                    fn($v) => is_int($v) || ctype_digit((string) $v)
                        ? (int) $v
                        : $this->resolveOptionId($vendor, $code, (string)$v),
                    $value
                );

                $customAttributes[] = [
                    'attribute_code' => $code,
                    'value' => implode(',', $resolvedValues),
                ];
                continue;
            }

            $resolvedValue = is_int($value) || ctype_digit((string) $value)
                ? (int) $value
                : $this->tryResolveOptionId($vendor, $code, (string)$value);

            $customAttributes[] = [
                'attribute_code' => $code,
                'value' => $resolvedValue,
            ];
        }

        if (!empty($product->categories) && is_array($product->categories)) {
            $customAttributes[] = [
                'attribute_code' => 'category_ids',
                'value' => array_values($product->categories),
            ];
        }

        return $customAttributes;
    }

    private function buildExtensionAttributes(ProductDraft|VendorProduct $product, Vendor $vendor, array $productData): array
    {
        $linkedProduct = $product instanceof ProductDraft ? $product->product : null;
        $magentoProductId = $product->magento_product_id ?? $linkedProduct?->magento_product_id;
        $isNewProduct = empty($magentoProductId);

        // Build stock item data WITHOUT item_id and product_id
        $stockItemData = [
            'qty' => (int) ($product->quantity ?? 0),
            'is_in_stock' => ((int) ($product->quantity ?? 0)) > 0,
            'is_qty_decimal' => data_get($productData, 'extension_attributes.stock_item.is_qty_decimal', false),
            'show_default_notification_message' => data_get($productData, 'extension_attributes.stock_item.show_default_notification_message', false),
            'use_config_min_qty' => data_get($productData, 'extension_attributes.stock_item.use_config_min_qty', true),
            'min_qty' => data_get($productData, 'extension_attributes.stock_item.min_qty', 0),
            'use_config_min_sale_qty' => data_get($productData, 'extension_attributes.stock_item.use_config_min_sale_qty', 1),
            'min_sale_qty' => data_get($productData, 'extension_attributes.stock_item.min_sale_qty', 1),
            'use_config_max_sale_qty' => data_get($productData, 'extension_attributes.stock_item.use_config_max_sale_qty', true),
            'max_sale_qty' => data_get($productData, 'extension_attributes.stock_item.max_sale_qty', 10000),
            'use_config_backorders' => data_get($productData, 'extension_attributes.stock_item.use_config_backorders', true),
            'backorders' => data_get($productData, 'extension_attributes.stock_item.backorders', 0),
            'use_config_notify_stock_qty' => data_get($productData, 'extension_attributes.stock_item.use_config_notify_stock_qty', true),
            'notify_stock_qty' => data_get($productData, 'extension_attributes.stock_item.notify_stock_qty', 5),
            'use_config_qty_increments' => data_get($productData, 'extension_attributes.stock_item.use_config_qty_increments', true),
            'qty_increments' => data_get($productData, 'extension_attributes.stock_item.qty_increments', 1),
            'use_config_enable_qty_inc' => data_get($productData, 'extension_attributes.stock_item.use_config_enable_qty_inc', true),
            'enable_qty_increments' => data_get($productData, 'extension_attributes.stock_item.enable_qty_increments', false),
            'use_config_manage_stock' => data_get($productData, 'extension_attributes.stock_item.use_config_manage_stock', true),
            'manage_stock' => data_get($productData, 'extension_attributes.stock_item.manage_stock', true),
            'is_decimal_divided' => data_get($productData, 'extension_attributes.stock_item.is_decimal_divided', false),
            'stock_status_changed_auto' => data_get($productData, 'extension_attributes.stock_item.stock_status_changed_auto', 0),
        ];

        // For existing products, DO NOT include item_id and product_id
        // Let Magento handle it automatically
        if (!$isNewProduct) {
            unset($stockItemData['item_id']);
            unset($stockItemData['product_id']);
        }

        $extensionAttributes = [
            'stock_item' => $stockItemData
        ];

        // Filter out null values for new products
        if ($isNewProduct) {
            $extensionAttributes['stock_item'] = array_filter(
                $extensionAttributes['stock_item'],
                fn($value) => $value !== null
            );
        }

        // Add other extension attributes only if they exist
        if ($websiteIds = data_get($productData, 'extension_attributes.website_ids')) {
            $extensionAttributes['website_ids'] = $websiteIds;
        }

        if ($categoryLinks = data_get($productData, 'extension_attributes.category_links')) {
            $extensionAttributes['category_links'] = $categoryLinks;
        }

        if ($discounts = data_get($productData, 'extension_attributes.discounts')) {
            $extensionAttributes['discounts'] = $discounts;
        }

        if ($bundleOptions = data_get($productData, 'extension_attributes.bundle_product_options')) {
            $extensionAttributes['bundle_product_options'] = $bundleOptions;
        }

        if ($downloadableLinks = data_get($productData, 'extension_attributes.downloadable_product_links')) {
            $extensionAttributes['downloadable_product_links'] = $downloadableLinks;
        }

        if ($downloadableSamples = data_get($productData, 'extension_attributes.downloadable_product_samples')) {
            $extensionAttributes['downloadable_product_samples'] = $downloadableSamples;
        }

        if ($giftcardAmounts = data_get($productData, 'extension_attributes.giftcard_amounts')) {
            $extensionAttributes['giftcard_amounts'] = $giftcardAmounts;
        }

        if ($configurableOptions = data_get($productData, 'extension_attributes.configurable_product_options')) {
            $extensionAttributes['configurable_product_options'] = $configurableOptions;
        }

        if ($configurableLinks = data_get($productData, 'extension_attributes.configurable_product_links')) {
            $extensionAttributes['configurable_product_links'] = $configurableLinks;
        }

        return $extensionAttributes;
    }

    private function buildProductLinks(ProductDraft|VendorProduct $product, array $productData): array
    {
        $productLinks = [];

        if ($links = data_get($productData, 'product_links')) {
            foreach ($links as $link) {
                $productLinks[] = [
                    'sku' => $link['sku'] ?? '',
                    'link_type' => $link['link_type'] ?? '',
                    'linked_product_sku' => $link['linked_product_sku'] ?? '',
                    'linked_product_type' => $link['linked_product_type'] ?? '',
                    'position' => $link['position'] ?? 0,
                    'extension_attributes' => $link['extension_attributes'] ?? ['qty' => 0]
                ];
            }
        }

        return $productLinks;
    }

    private function buildOptions(ProductDraft|VendorProduct $product, array $productData): array
    {
        $options = [];

        if ($customOptions = data_get($productData, 'options')) {
            foreach ($customOptions as $option) {
                $formattedOption = [
                    'product_sku' => $option['product_sku'] ?? $product->sku,
                    'option_id' => $option['option_id'] ?? 0,
                    'title' => $option['title'] ?? '',
                    'type' => $option['type'] ?? '',
                    'sort_order' => $option['sort_order'] ?? 0,
                    'is_require' => $option['is_require'] ?? true,
                    'price' => $option['price'] ?? 0,
                    'price_type' => $option['price_type'] ?? 'fixed',
                    'sku' => $option['sku'] ?? '',
                    'file_extension' => $option['file_extension'] ?? '',
                    'max_characters' => $option['max_characters'] ?? 0,
                    'image_size_x' => $option['image_size_x'] ?? 0,
                    'image_size_y' => $option['image_size_y'] ?? 0,
                ];

                if (!empty($option['values'])) {
                    $formattedOption['values'] = [];
                    foreach ($option['values'] as $value) {
                        $formattedOption['values'][] = [
                            'title' => $value['title'] ?? '',
                            'sort_order' => $value['sort_order'] ?? 0,
                            'price' => $value['price'] ?? 0,
                            'price_type' => $value['price_type'] ?? 'fixed',
                            'sku' => $value['sku'] ?? '',
                            'option_type_id' => $value['option_type_id'] ?? 0
                        ];
                    }
                }

                $options[] = $formattedOption;
            }
        }

        return $options;
    }

    private function buildMediaGalleryEntries(ProductDraft|VendorProduct $product, array $productData): array
    {
        $mediaEntries = [];

        // Check for media_gallery_entries in product_data
        if ($mediaGallery = data_get($productData, 'media_gallery_entries')) {
            foreach ($mediaGallery as $media) {
                $entry = [
                    'id' => $media['id'] ?? 0,
                    'media_type' => $media['media_type'] ?? 'image',
                    'label' => $media['label'] ?? '',
                    'position' => $media['position'] ?? 0,
                    'disabled' => $media['disabled'] ?? false,
                    'types' => $media['types'] ?? [],
                    'file' => $media['file'] ?? '',
                ];

                // Handle base64 encoded image content
                if (!empty($media['content'])) {
                    // SANITIZE THE IMAGE NAME HERE
                    $originalName = $media['content']['name'] ?? 'image.jpg';
                    $sanitizedName = $this->sanitizeImageName($originalName);

                    $entry['content'] = [
                        'base64_encoded_data' => $media['content']['base64_encoded_data'] ?? '',
                        'type' => $media['content']['type'] ?? 'image/jpeg',
                        'name' => $sanitizedName, // Use sanitized name
                    ];
                }
                // Alternative: handle if image data is in a different format
                elseif (!empty($media['base64_encoded_data'])) {
                    $originalName = $media['name'] ?? 'image.jpg';
                    $sanitizedName = $this->sanitizeImageName($originalName);

                    $entry['content'] = [
                        'base64_encoded_data' => $media['base64_encoded_data'],
                        'type' => $media['content_type'] ?? 'image/jpeg',
                        'name' => $sanitizedName, // Use sanitized name
                    ];
                }

                if (!empty($media['extension_attributes']['video_content'])) {
                    $entry['extension_attributes']['video_content'] = [
                        'media_type' => $media['extension_attributes']['video_content']['media_type'] ?? '',
                        'video_provider' => $media['extension_attributes']['video_content']['video_provider'] ?? '',
                        'video_url' => $media['extension_attributes']['video_content']['video_url'] ?? '',
                        'video_title' => $media['extension_attributes']['video_content']['video_title'] ?? '',
                        'video_description' => $media['extension_attributes']['video_content']['video_description'] ?? '',
                        'video_metadata' => $media['extension_attributes']['video_content']['video_metadata'] ?? ''
                    ];
                }

                $mediaEntries[] = $entry;
            }
        }

        // Also handle media_gallery field if present (alternative format)
        elseif ($mediaGallery = data_get($productData, 'media_gallery')) {
            foreach ($mediaGallery as $media) {
                $entry = [
                    'media_type' => $media['media_type'] ?? 'image',
                    'label' => $media['label'] ?? '',
                    'position' => $media['position'] ?? 0,
                    'disabled' => $media['disabled'] ?? false,
                    'types' => $media['types'] ?? [],
                ];

                if (!empty($media['content'])) {
                    // SANITIZE THE IMAGE NAME HERE
                    $originalName = $media['content']['name'] ?? 'image.jpg';
                    $sanitizedName = $this->sanitizeImageName($originalName);

                    $entry['content'] = [
                        'base64_encoded_data' => $media['content']['base64_encoded_data'] ?? '',
                        'type' => $media['content']['type'] ?? 'image/jpeg',
                        'name' => $sanitizedName, // Use sanitized name
                    ];
                } elseif (!empty($media['url'])) {
                    $entry['file'] = $media['url'];
                }

                $mediaEntries[] = $entry;
            }
        }

        return $mediaEntries;
    }

    private function buildTierPrices(ProductDraft|VendorProduct $product, array $productData): array
    {
        $tierPrices = [];

        if ($prices = data_get($productData, 'tier_prices')) {
            foreach ($prices as $price) {
                $tierPrices[] = [
                    'customer_group_id' => $price['customer_group_id'] ?? 0,
                    'qty' => $price['qty'] ?? 0,
                    'value' => $price['value'] ?? 0,
                    'extension_attributes' => [
                        'percentage_value' => $price['extension_attributes']['percentage_value'] ?? 0,
                        'website_id' => $price['extension_attributes']['website_id'] ?? 0
                    ]
                ];
            }
        }

        return $tierPrices;
    }
    /**
     * Resolve option ID for attribute
     */
    private function resolveOptionId(Vendor $vendor, string $attributeCode, string $label): int
    {
        $magentoService = $this->getMagentoServiceForVendor($vendor);

        $options = Cache::remember(
            "magento_attr_opts_{$vendor->id}_{$attributeCode}",
            now()->addHours(24),
            fn() => $magentoService->get("products/attributes/{$attributeCode}/options")
        );

        $match = collect($options)->first(
            fn($opt) => strtolower(trim($opt['label'])) === strtolower(trim($label))
        );

        if (!$match) {
            $available = collect($options)
                ->filter(fn($o) => $o['value'] !== '')
                ->pluck('label')
                ->implode(', ');

            throw new \RuntimeException(
                "Magento attribute '{$attributeCode}': no option found for '{$label}'. " .
                    "Available options: {$available}"
            );
        }

        return (int) $match['value'];
    }

    /**
     * Try to resolve option ID, fallback to raw value
     */
    private function tryResolveOptionId(Vendor $vendor, string $attributeCode, string $value): int|string
    {
        try {
            $magentoService = $this->getMagentoServiceForVendor($vendor);

            $options = Cache::remember(
                "magento_attr_opts_{$vendor->id}_{$attributeCode}",
                now()->addHours(24),
                fn() => $magentoService->get("products/attributes/{$attributeCode}/options")
            );

            $filteredOptions = collect($options)->filter(fn($o) => $o['value'] !== '');

            if ($filteredOptions->isEmpty()) {
                return $value;
            }

            $match = $filteredOptions->first(
                fn($opt) => strtolower(trim($opt['label'])) === strtolower(trim($value))
            );

            return $match ? (int) $match['value'] : $value;
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Merge metadata
     */
    private function mergedMetadata(?VendorProduct $product, array $magentoProduct, array $extra = []): array
    {
        return array_merge($product?->metadata ?? [], $extra, [
            'magento' => $magentoProduct,
            'magento_synced_at' => now()->toIso8601String(),
        ]);
    }
}
