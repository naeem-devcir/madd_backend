<?php

// namespace App\Services\Product;

// use App\Models\Product\ProductDraft;
// use App\Models\Product\ProductApproval;
// use App\Models\Product\VendorProduct;
// use App\Models\Vendor\Vendor;
// use App\Models\Vendor\VendorStore;
// use App\Services\Integration\MagentoService;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Cache;

// class ProductService
// {
//     /**
//      * Get Magento service instance for a specific vendor
//      */
//     private function getMagentoServiceForVendor(Vendor $vendor): MagentoService
//     {
//         return MagentoService::forVendor($vendor);
//     }

//     /**
//      * Submit product draft for approval
//      */
//     public function submitForApproval(ProductDraft $draft): void
//     {
//         $draft->status = 'pending';
//         $draft->save();

//         ProductApproval::create([
//             'product_draft_id' => $draft->id,
//             'vendor_id' => $draft->vendor_id,
//             'approval_type' => $draft->vendor_product_id ? 'update' : 'new',
//             'submitted_data' => $draft->toArray(),
//             'status' => 'pending',
//         ]);

//         \App\Jobs\Notification\SendProductApprovalNotification::dispatch($draft);
//     }

//     /**
//      * Approve product draft and sync to Magento
//      */
//     public function approveProduct(ProductDraft $draft, int $adminId, ?string $notes = null): VendorProduct
//     {
//         DB::beginTransaction();

//         try {
//             $draft->loadMissing('vendor', 'store', 'product');

//             $draft->status = 'approved';
//             $draft->reviewed_by = $adminId;
//             $draft->reviewed_at = now();
//             $draft->review_notes = $notes;
//             $draft->save();

//             $magentoProduct = $this->createOrUpdateProductInMagento($draft);

//             if ($draft->vendor_product_id) {
//                 $product = $draft->product;
//                 $product->update([
//                     'magento_product_id' => $magentoProduct['id'],
//                     'magento_sku' => $magentoProduct['sku'],
//                     'sku' => $draft->sku,
//                     'name' => $draft->name,
//                     'price' => $draft->price,
//                     'quantity' => $draft->quantity,
//                     'status' => 'active',
//                     'sync_status' => 'synced',
//                     'last_synced_at' => now(),
//                     'sync_errors' => null,
//                     'metadata' => $this->mergedMetadata($product, $magentoProduct),
//                 ]);
//             } else {
//                 $product = VendorProduct::create([
//                     'vendor_id' => $draft->vendor_id,
//                     'vendor_store_id' => $draft->vendor_store_id,
//                     'magento_product_id' => $magentoProduct['id'],
//                     'magento_sku' => $magentoProduct['sku'],
//                     'sku' => $draft->sku,
//                     'name' => $draft->name,
//                     'type_id' => $magentoProduct['type_id'] ?? 'simple',
//                     'attribute_set_id' => $magentoProduct['attribute_set_id'] ?? 4,
//                     'price' => $draft->price,
//                     'quantity' => $draft->quantity,
//                     'status' => 'active',
//                     'sync_status' => 'synced',
//                     'last_synced_at' => now(),
//                     'metadata' => [
//                         'magento' => $magentoProduct,
//                         'created_by_admin_id' => $adminId,
//                     ],
//                 ]);

//                 $draft->vendor_product_id = $product->id;
//             }

//             $draft->published_at = now();
//             $draft->save();

//             $approval = ProductApproval::where('product_draft_id', $draft->id)->first();
//             if ($approval) {
//                 $approval->status = 'approved';
//                 $approval->reviewed_by = $adminId;
//                 $approval->reviewed_at = now();
//                 $approval->admin_notes = $notes;
//                 $approval->save();
//             }

//             DB::commit();

//             event(new \App\Events\Product\ProductApproved($product, $draft));
//             \App\Jobs\Notification\SendProductApprovedNotification::dispatch($product);

//             return $product;
//         } catch (\Exception $e) {
//             DB::rollBack();
//             throw $e;
//         }
//     }

//     /**
//      * Reject product draft
//      */
//     public function rejectProduct(ProductDraft $draft, int $adminId, string $reason): void
//     {
//         DB::beginTransaction();

//         try {
//             $draft->status = 'rejected';
//             $draft->rejection_reason = $reason;
//             $draft->reviewed_by = $adminId;
//             $draft->reviewed_at = now();
//             $draft->save();

//             $approval = ProductApproval::where('product_draft_id', $draft->id)->first();
//             $approval->status = 'rejected';
//             $approval->rejection_reason = $reason;
//             $approval->reviewed_by = $adminId;
//             $approval->reviewed_at = now();
//             $approval->save();

//             DB::commit();

//             \App\Jobs\Notification\SendProductRejectedNotification::dispatch($draft, $reason);
//         } catch (\Exception $e) {
//             DB::rollBack();
//             throw $e;
//         }
//     }

//     /**
//      * Request modification for product draft
//      */
//     public function requestModification(ProductDraft $draft, int $adminId, string $notes): void
//     {
//         DB::beginTransaction();

//         try {
//             $draft->status = 'needs_modification';
//             $draft->review_notes = $notes;
//             $draft->reviewed_by = $adminId;
//             $draft->reviewed_at = now();
//             $draft->save();

//             $approval = ProductApproval::where('product_draft_id', $draft->id)->first();
//             $approval->status = 'needs_modification';
//             $approval->admin_notes = $notes;
//             $approval->reviewed_by = $adminId;
//             $approval->reviewed_at = now();
//             $approval->save();

//             DB::commit();

//             \App\Jobs\Notification\SendProductModificationRequestNotification::dispatch($draft, $notes);
//         } catch (\Exception $e) {
//             DB::rollBack();
//             throw $e;
//         }
//     }

//     /**
//      * Create new version of product draft
//      */
//     public function createNewVersion(ProductDraft $draft): ProductDraft
//     {
//         $newDraft = $draft->replicate();
//         $newDraft->version = $draft->version + 1;
//         $newDraft->parent_draft_id = $draft->id;
//         $newDraft->status = 'draft';
//         $newDraft->reviewed_by = null;
//         $newDraft->reviewed_at = null;
//         $newDraft->rejection_reason = null;
//         $newDraft->review_notes = null;
//         $newDraft->save();

//         return $newDraft;
//     }

//     /**
//      * Get product validation rules
//      */
//     public function getValidationRules(Vendor $vendor): array
//     {
//         return [
//             'sku' => 'required|string|max:255|unique:vendor_products,sku,NULL,id,vendor_id,' . $vendor->getKey(),
//             'name' => 'required|string|max:500',
//             'description' => 'nullable|string',
//             'price' => 'required|numeric|min:0',
//             'quantity' => 'required|integer|min:0',
//             'weight' => 'nullable|numeric|min:0',
//             'categories' => 'nullable|array',
//             'images' => 'nullable|array|max:10',
//             'images.*' => 'image|max:5120',
//         ];
//     }

//     /**
//      * Create product directly by admin (bypasses approval)
//      */
//     public function createAdminProduct(array $data, int $adminId): VendorProduct
//     {
//         $vendor = Vendor::findOrFail($data['vendor_id']);
//         $store = VendorStore::where('vendor_id', $vendor->getKey())->findOrFail($data['vendor_store_id']);
//         $productData = $this->mergeProductData($data);

//         $draft = new ProductDraft([
//             'vendor_id' => $vendor->getKey(),
//             'vendor_store_id' => $store->id,
//             'sku' => $data['sku'],
//             'name' => $data['name'],
//             'description' => $data['description'] ?? null,
//             'short_description' => $data['short_description'] ?? null,
//             'price' => $data['price'],
//             'special_price' => $data['special_price'] ?? null,
//             'special_price_from' => $data['special_price_from'] ?? null,
//             'special_price_to' => $data['special_price_to'] ?? null,
//             'quantity' => $data['quantity'] ?? 0,
//             'weight' => $data['weight'] ?? 0,
//             'product_data' => $productData,
//             'media_gallery' => $data['media_gallery'] ?? null,
//             'categories' => $data['categories'] ?? null,
//             'attributes' => $data['attributes'] ?? null,
//             'seo_data' => $data['seo_data'] ?? null,
//             'status' => 'approved',
//             'reviewed_by' => $adminId,
//             'reviewed_at' => now(),
//             'published_at' => now(),
//         ]);

//         $draft->setRelation('vendor', $vendor);
//         $draft->setRelation('store', $store);

//         // REMOVE these lines - they don't exist in product_drafts table:
//         // $draft->type_id = $data['type_id'] ?? 'simple';
//         // $draft->attribute_set_id = $data['attribute_set_id'] ?? 4;
//         // $draft->visibility = $data['visibility'] ?? 4;

//         $magentoProduct = $this->createOrUpdateProductInMagento($draft);

//         return DB::transaction(function () use ($draft, $magentoProduct, $adminId, $data, $productData) {
//             // Get Magento-specific fields from the merged payload.
//             $typeId = $productData['type_id'] ?? 'simple';
//             $attributeSetId = $productData['attribute_set_id'] ?? 4;

//             $product = VendorProduct::create([
//                 'vendor_id' => $draft->vendor_id,
//                 'vendor_store_id' => $draft->vendor_store_id,
//                 'magento_product_id' => $magentoProduct['id'],
//                 'magento_sku' => $magentoProduct['sku'],
//                 'sku' => $draft->sku,
//                 'name' => $draft->name,
//                 'type_id' => $magentoProduct['type_id'] ?? $typeId,
//                 'attribute_set_id' => $magentoProduct['attribute_set_id'] ?? $attributeSetId,
//                 'price' => $draft->price,
//                 'quantity' => $draft->quantity,
//                 'status' => $data['status'] ?? 'active',
//                 'sync_status' => 'synced',
//                 'last_synced_at' => now(),
//                 'metadata' => [
//                     'magento' => $magentoProduct,
//                     'created_by_admin_id' => $adminId,
//                     'full_payload' => $productData,
//                 ],
//             ]);

//             $draft->vendor_product_id = $product->id;
//             $draft->save();

//             return $product;
//         });
//     }

//     /**
//      * Update product directly by admin (bypasses approval)
//      */
//     public function updateAdminProduct(VendorProduct $product, array $data, int $adminId): VendorProduct
//     {
//         $product->loadMissing('vendor', 'store');

//         $productData = $this->mergeProductData($data, $product);

//         $draft = new ProductDraft([
//             'vendor_id' => $product->vendor_id,
//             'vendor_store_id' => $product->vendor_store_id,
//             'vendor_product_id' => $product->id,
//             'magento_product_id' => $product->magento_product_id,
//             'sku' => $data['sku'] ?? $product->sku,
//             'name' => $data['name'] ?? $product->name,
//             'description' => $data['description'] ?? $product->draft?->description,
//             'short_description' => $data['short_description'] ?? $product->draft?->short_description,
//             'price' => $data['price'] ?? $product->price,
//             'special_price' => $data['special_price'] ?? $product->draft?->special_price,
//             'special_price_from' => $data['special_price_from'] ?? $product->draft?->special_price_from,
//             'special_price_to' => $data['special_price_to'] ?? $product->draft?->special_price_to,
//             'quantity' => $data['quantity'] ?? $product->quantity,
//             'weight' => $data['weight'] ?? $product->draft?->weight ?? 0,
//             'product_data' => $productData,
//             'media_gallery' => $data['media_gallery'] ?? $product->draft?->media_gallery,
//             'categories' => $data['categories'] ?? $product->draft?->categories,
//             'attributes' => $data['attributes'] ?? $product->draft?->attributes,
//             'seo_data' => $data['seo_data'] ?? $product->draft?->seo_data,
//             'status' => 'approved',
//             'reviewed_by' => $adminId,
//             'reviewed_at' => now(),
//             'published_at' => now(),
//         ]);

//         $draft->setRelation('vendor', $product->vendor);
//         $draft->setRelation('store', $product->store);
//         $draft->setRelation('product', $product);

//         $magentoProduct = $this->createOrUpdateProductInMagento($draft);

//         return DB::transaction(function () use ($product, $draft, $magentoProduct, $adminId, $data, $productData) {
//             $product->update([
//                 'magento_product_id' => $magentoProduct['id'] ?? $product->magento_product_id,
//                 'magento_sku' => $magentoProduct['sku'] ?? $draft->sku,
//                 'sku' => $draft->sku,
//                 'name' => $draft->name,
//                 'type_id' => $magentoProduct['type_id'] ?? $product->type_id,
//                 'attribute_set_id' => $magentoProduct['attribute_set_id'] ?? $product->attribute_set_id,
//                 'price' => $draft->price,
//                 'quantity' => $draft->quantity,
//                 'status' => $data['status'] ?? $product->status,
//                 'sync_status' => 'synced',
//                 'last_synced_at' => now(),
//                 'sync_errors' => null,
//                 'metadata' => $this->mergedMetadata($product, $magentoProduct, [
//                     'updated_by_admin_id' => $adminId,
//                     'full_payload' => $productData,
//                 ]),
//             ]);

//             $draft->vendor_product_id = $product->id;
//             $draft->save();

//             return $product->refresh();
//         });
//     }

//     /**
//      * Delete product from admin
//      */
//     public function deleteAdminProduct(VendorProduct $product, int $adminId): array
//     {
//         $product->loadMissing('vendor');

//         if ($product->orderItems()->exists()) {
//             return [
//                 'deleted' => false,
//                 'blocked' => true,
//                 'reason' => 'Cannot delete product with existing orders',
//                 'order_count' => $product->orderItems()->count(),
//             ];
//         }

//         $magentoResult = $this->deleteProductFromMagento($product);

//         DB::transaction(function () use ($product, $adminId, $magentoResult) {
//             $product->update([
//                 'status' => 'deleted',
//                 'sync_status' => 'deleted',
//                 'last_synced_at' => now(),
//                 'sync_errors' => null,
//                 'metadata' => $this->mergedMetadata($product, $magentoResult, [
//                     'deleted_by_admin_id' => $adminId,
//                     'deleted_at' => now()->toIso8601String(),
//                 ]),
//             ]);

//             $product->delete();
//         });

//         return [
//             'deleted' => true,
//             'blocked' => false,
//             'magento' => $magentoResult,
//         ];
//     }

//     /**
//      * Sync product stock to Magento
//      */
//     public function syncStock(VendorProduct $product): array
//     {
//         $vendor = $product->vendor;
//         if (!$vendor || !$vendor->id) {
//             throw new \Exception('Product has no associated vendor');
//         }

//         $magentoService = $this->getMagentoServiceForVendor($vendor);
//         $sku = $product->magento_sku ?: $product->sku;

//         return $magentoService->put('products/' . rawurlencode($sku) . '/stockItems/1', [
//             'stockItem' => [
//                 'qty' => (int) $product->quantity,
//                 'is_in_stock' => $product->quantity > 0
//             ]
//         ]);
//     }

//     /**
//      * Get products from Magento with filters
//      */
//     public function getProductsFromMagento(Vendor $vendor, array $filters = [], int $page = 1, int $size = 20): array
//     {
//         $magentoService = $this->getMagentoServiceForVendor($vendor);

//         $params = [
//             'searchCriteria[currentPage]' => $page,
//             'searchCriteria[pageSize]' => $size,
//         ];

//         if (!empty($filters['category_id'])) {
//             $params['searchCriteria[filterGroups][0][filters][0][field]'] = 'category_id';
//             $params['searchCriteria[filterGroups][0][filters][0][value]'] = $filters['category_id'];
//             $params['searchCriteria[filterGroups][0][filters][0][conditionType]'] = 'eq';
//         }

//         if (!empty($filters['price_from'])) {
//             $params['searchCriteria[filterGroups][1][filters][0][field]'] = 'price';
//             $params['searchCriteria[filterGroups][1][filters][0][value]'] = $filters['price_from'];
//             $params['searchCriteria[filterGroups][1][filters][0][conditionType]'] = 'gteq';
//         }

//         if (!empty($filters['price_to'])) {
//             $params['searchCriteria[filterGroups][2][filters][0][field]'] = 'price';
//             $params['searchCriteria[filterGroups][2][filters][0][value]'] = $filters['price_to'];
//             $params['searchCriteria[filterGroups][2][filters][0][conditionType]'] = 'lteq';
//         }

//         return $magentoService->get('products', $params);
//     }

//     /**
//      * Get single product from Magento by SKU
//      */
//     public function getProductFromMagento(Vendor $vendor, string $sku): array
//     {
//         $magentoService = $this->getMagentoServiceForVendor($vendor);
//         return $magentoService->get('products/' . rawurlencode($sku));
//     }

//     // ─────────────────────────────────────────────────────
//     // PRIVATE HELPER METHODS
//     // ─────────────────────────────────────────────────────

//     /**
//      * Create or update product in Magento
//      */
//     private function createOrUpdateProductInMagento(ProductDraft|VendorProduct $product): array
//     {
//         // Get the vendor from the product
//         $vendor = $product->vendor;
//         if (!$vendor || !$vendor->id) {
//             throw new \Exception('Product has no associated vendor');
//         }

//         // Create service for this specific vendor
//         $magentoService = $this->getMagentoServiceForVendor($vendor);

//         $payload = $this->buildProductPayload($product);
//         $existingProduct = $product instanceof ProductDraft ? $product->product : null;
//         $magentoSku = $product->magento_sku
//             ?? $existingProduct?->magento_sku
//             ?? null;
//         $magentoProductId = $product->magento_product_id
//             ?? $existingProduct?->magento_product_id
//             ?? null;

//         if (!empty($magentoProductId) || !empty($magentoSku)) {
//             return $magentoService->put(
//                 'products/' . rawurlencode($magentoSku ?: $product->sku),
//                 ['product' => $payload]
//             );
//         }

//         return $magentoService->post('products', ['product' => $payload]);
//     }
//     /**
//      * Sanitize image filename for Magento
//      */
//     /**
//      * Sanitize image filename for Magento
//      */
//     private function sanitizeImageName(string $filename): string
//     {
//         // Get extension
//         $extension = pathinfo($filename, PATHINFO_EXTENSION);
//         $extension = $extension ?: 'jpg';

//         // Remove any non-alphanumeric characters except dot, dash, and underscore
//         $basename = pathinfo($filename, PATHINFO_FILENAME);
//         // Allow letters, numbers, dashes, underscores, and dots
//         $basename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $basename);

//         // Limit length to 30 characters
//         $basename = substr($basename, 0, 30);

//         // If basename is empty, generate a random name
//         if (empty($basename)) {
//             $basename = 'image_' . time() . '_' . bin2hex(random_bytes(4));
//         }

//         // Ensure the name doesn't start or end with special characters
//         $basename = trim($basename, '.-_');

//         // If still empty, use timestamp
//         if (empty($basename)) {
//             $basename = (string) time();
//         }

//         return $basename . '.' . $extension;
//     }
//     /**
//      * Delete product from Magento
//      */
//     private function deleteProductFromMagento(VendorProduct $product): array
//     {
//         $vendor = $product->vendor;
//         if (!$vendor || !$vendor->id) {
//             throw new \Exception('Product has no associated vendor');
//         }

//         $magentoService = $this->getMagentoServiceForVendor($vendor);
//         $sku = $product->magento_sku ?: $product->sku;

//         return $magentoService->delete('products/' . rawurlencode($sku));
//     }

//     /**
//      * Merge Magento-specific payload fields from top-level and product_data.
//      */
//     private function mergeProductData(array $data, ?VendorProduct $existingProduct = null): array
//     {
//         $existingPayload = data_get($existingProduct?->metadata, 'full_payload', []);
//         $nestedPayload = $data['product_data'] ?? [];
//         $topLevelPayload = $data;
//         unset($topLevelPayload['product_data']);

//         $merged = array_replace_recursive($existingPayload, $nestedPayload, $topLevelPayload);

//         if ($existingProduct) {
//             $merged['type_id'] = $merged['type_id'] ?? $existingProduct->type_id ?? 'simple';
//             $merged['attribute_set_id'] = $merged['attribute_set_id'] ?? $existingProduct->attribute_set_id ?? 4;
//             $merged['status'] = $merged['status'] ?? $existingProduct->status ?? 'active';
//         }

//         return $merged;
//     }

//     /**
//      * Build product payload for Magento API
//      */
//     private function buildProductPayload(ProductDraft|VendorProduct $product): array
//     {
//         $vendor = $product->vendor;
//         if (!$vendor || !$vendor->id) {
//             throw new \Exception('Cannot build product payload: No vendor associated');
//         }

//         // Get the full product data from product_data JSON field
//         $linkedProduct = $product instanceof ProductDraft ? $product->product : null;
//         $productData = $product instanceof ProductDraft
//             ? $this->mergeProductData($product->product_data ?? [], $linkedProduct)
//             : $this->mergeProductData($product->metadata['full_payload'] ?? [], $product);

//         $attributes = is_array($product->attributes ?? null) ? $product->attributes : [];

//         // Get Magento-specific fields from product_data
//         $typeId = $productData['type_id'] ?? $product->type_id ?? $linkedProduct?->type_id ?? 'simple';
//         $attributeSetId = $productData['attribute_set_id'] ?? $product->attribute_set_id ?? $linkedProduct?->attribute_set_id ?? 4;
//         $visibility = $productData['visibility'] ?? 4;

//         $requestedStatus = $product instanceof ProductDraft
//             ? data_get($productData, 'status', $linkedProduct?->status ?? 'active')
//             : ($product->status ?? 'active');

//         $payload = [
//             'sku' => $product->sku,
//             'name' => $product->name,
//             'attribute_set_id' => (int) $attributeSetId,
//             'price' => (float) ($product->price ?? 0),
//             'status' => $requestedStatus === 'inactive' ? 2 : 1,
//             'visibility' => (int) $visibility,
//             'type_id' => $typeId,
//             'weight' => (float) ($product->weight ?? 0),
//             'extension_attributes' => $this->buildExtensionAttributes($product, $vendor, $productData),
//             'product_links' => $productData['product_links'] ?? [],
//             'options' => $productData['options'] ?? [],
//             'media_gallery_entries' => $this->buildMediaGalleryEntries($product, $productData),
//             'tier_prices' => $productData['tier_prices'] ?? [],
//             'custom_attributes' => $this->buildCustomAttributes($product, $vendor, $attributes),
//         ];

//         // Add created_at and updated_at if they exist in product_data
//         if (isset($productData['created_at'])) {
//             $payload['created_at'] = $productData['created_at'];
//         }

//         if (isset($productData['updated_at'])) {
//             $payload['updated_at'] = $productData['updated_at'];
//         }

//         return array_filter($payload, fn($value) => $value !== null);
//     }


//     private function buildCustomAttributes(ProductDraft|VendorProduct $product, Vendor $vendor, array $attributes): array
//     {
//         $customAttributes = [
//             ['attribute_code' => 'description', 'value' => $product->description ?? $attributes['description'] ?? ''],
//             ['attribute_code' => 'short_description', 'value' => $product->short_description ?? $attributes['short_description'] ?? ''],
//         ];

//         $skipCodes = ['description', 'short_description'];

//         foreach ($attributes as $code => $value) {
//             if (in_array($code, $skipCodes, true)) {
//                 continue;
//             }

//             if (is_array($value)) {
//                 $resolvedValues = array_map(
//                     fn($v) => is_int($v) || ctype_digit((string) $v)
//                         ? (int) $v
//                         : $this->resolveOptionId($vendor, $code, (string)$v),
//                     $value
//                 );

//                 $customAttributes[] = [
//                     'attribute_code' => $code,
//                     'value' => implode(',', $resolvedValues),
//                 ];
//                 continue;
//             }

//             $resolvedValue = is_int($value) || ctype_digit((string) $value)
//                 ? (int) $value
//                 : $this->tryResolveOptionId($vendor, $code, (string)$value);

//             $customAttributes[] = [
//                 'attribute_code' => $code,
//                 'value' => $resolvedValue,
//             ];
//         }

//         if (!empty($product->categories) && is_array($product->categories)) {
//             $customAttributes[] = [
//                 'attribute_code' => 'category_ids',
//                 'value' => array_values($product->categories),
//             ];
//         }

//         return $customAttributes;
//     }

//     private function buildExtensionAttributes(ProductDraft|VendorProduct $product, Vendor $vendor, array $productData): array
//     {
//         $linkedProduct = $product instanceof ProductDraft ? $product->product : null;
//         $magentoProductId = $product->magento_product_id ?? $linkedProduct?->magento_product_id;
//         $isNewProduct = empty($magentoProductId);

//         // Build stock item data WITHOUT item_id and product_id
//         $stockItemData = [
//             'qty' => (int) ($product->quantity ?? 0),
//             'is_in_stock' => ((int) ($product->quantity ?? 0)) > 0,
//             'is_qty_decimal' => data_get($productData, 'extension_attributes.stock_item.is_qty_decimal', false),
//             'show_default_notification_message' => data_get($productData, 'extension_attributes.stock_item.show_default_notification_message', false),
//             'use_config_min_qty' => data_get($productData, 'extension_attributes.stock_item.use_config_min_qty', true),
//             'min_qty' => data_get($productData, 'extension_attributes.stock_item.min_qty', 0),
//             'use_config_min_sale_qty' => data_get($productData, 'extension_attributes.stock_item.use_config_min_sale_qty', 1),
//             'min_sale_qty' => data_get($productData, 'extension_attributes.stock_item.min_sale_qty', 1),
//             'use_config_max_sale_qty' => data_get($productData, 'extension_attributes.stock_item.use_config_max_sale_qty', true),
//             'max_sale_qty' => data_get($productData, 'extension_attributes.stock_item.max_sale_qty', 10000),
//             'use_config_backorders' => data_get($productData, 'extension_attributes.stock_item.use_config_backorders', true),
//             'backorders' => data_get($productData, 'extension_attributes.stock_item.backorders', 0),
//             'use_config_notify_stock_qty' => data_get($productData, 'extension_attributes.stock_item.use_config_notify_stock_qty', true),
//             'notify_stock_qty' => data_get($productData, 'extension_attributes.stock_item.notify_stock_qty', 5),
//             'use_config_qty_increments' => data_get($productData, 'extension_attributes.stock_item.use_config_qty_increments', true),
//             'qty_increments' => data_get($productData, 'extension_attributes.stock_item.qty_increments', 1),
//             'use_config_enable_qty_inc' => data_get($productData, 'extension_attributes.stock_item.use_config_enable_qty_inc', true),
//             'enable_qty_increments' => data_get($productData, 'extension_attributes.stock_item.enable_qty_increments', false),
//             'use_config_manage_stock' => data_get($productData, 'extension_attributes.stock_item.use_config_manage_stock', true),
//             'manage_stock' => data_get($productData, 'extension_attributes.stock_item.manage_stock', true),
//             'is_decimal_divided' => data_get($productData, 'extension_attributes.stock_item.is_decimal_divided', false),
//             'stock_status_changed_auto' => data_get($productData, 'extension_attributes.stock_item.stock_status_changed_auto', 0),
//         ];

//         // For existing products, DO NOT include item_id and product_id
//         // Let Magento handle it automatically
//         if (!$isNewProduct) {
//             unset($stockItemData['item_id']);
//             unset($stockItemData['product_id']);
//         }

//         $extensionAttributes = [
//             'stock_item' => $stockItemData
//         ];

//         // Filter out null values for new products
//         if ($isNewProduct) {
//             $extensionAttributes['stock_item'] = array_filter(
//                 $extensionAttributes['stock_item'],
//                 fn($value) => $value !== null
//             );
//         }

//         // Add other extension attributes only if they exist
//         if ($websiteIds = data_get($productData, 'extension_attributes.website_ids')) {
//             $extensionAttributes['website_ids'] = $websiteIds;
//         }

//         if ($categoryLinks = data_get($productData, 'extension_attributes.category_links')) {
//             $extensionAttributes['category_links'] = $categoryLinks;
//         }

//         if ($discounts = data_get($productData, 'extension_attributes.discounts')) {
//             $extensionAttributes['discounts'] = $discounts;
//         }

//         if ($bundleOptions = data_get($productData, 'extension_attributes.bundle_product_options')) {
//             $extensionAttributes['bundle_product_options'] = $bundleOptions;
//         }

//         if ($downloadableLinks = data_get($productData, 'extension_attributes.downloadable_product_links')) {
//             $extensionAttributes['downloadable_product_links'] = $downloadableLinks;
//         }

//         if ($downloadableSamples = data_get($productData, 'extension_attributes.downloadable_product_samples')) {
//             $extensionAttributes['downloadable_product_samples'] = $downloadableSamples;
//         }

//         if ($giftcardAmounts = data_get($productData, 'extension_attributes.giftcard_amounts')) {
//             $extensionAttributes['giftcard_amounts'] = $giftcardAmounts;
//         }

//         if ($configurableOptions = data_get($productData, 'extension_attributes.configurable_product_options')) {
//             $extensionAttributes['configurable_product_options'] = $configurableOptions;
//         }

//         if ($configurableLinks = data_get($productData, 'extension_attributes.configurable_product_links')) {
//             $extensionAttributes['configurable_product_links'] = $configurableLinks;
//         }

//         return $extensionAttributes;
//     }

//     private function buildProductLinks(ProductDraft|VendorProduct $product, array $productData): array
//     {
//         $productLinks = [];

//         if ($links = data_get($productData, 'product_links')) {
//             foreach ($links as $link) {
//                 $productLinks[] = [
//                     'sku' => $link['sku'] ?? '',
//                     'link_type' => $link['link_type'] ?? '',
//                     'linked_product_sku' => $link['linked_product_sku'] ?? '',
//                     'linked_product_type' => $link['linked_product_type'] ?? '',
//                     'position' => $link['position'] ?? 0,
//                     'extension_attributes' => $link['extension_attributes'] ?? ['qty' => 0]
//                 ];
//             }
//         }

//         return $productLinks;
//     }

//     private function buildOptions(ProductDraft|VendorProduct $product, array $productData): array
//     {
//         $options = [];

//         if ($customOptions = data_get($productData, 'options')) {
//             foreach ($customOptions as $option) {
//                 $formattedOption = [
//                     'product_sku' => $option['product_sku'] ?? $product->sku,
//                     'option_id' => $option['option_id'] ?? 0,
//                     'title' => $option['title'] ?? '',
//                     'type' => $option['type'] ?? '',
//                     'sort_order' => $option['sort_order'] ?? 0,
//                     'is_require' => $option['is_require'] ?? true,
//                     'price' => $option['price'] ?? 0,
//                     'price_type' => $option['price_type'] ?? 'fixed',
//                     'sku' => $option['sku'] ?? '',
//                     'file_extension' => $option['file_extension'] ?? '',
//                     'max_characters' => $option['max_characters'] ?? 0,
//                     'image_size_x' => $option['image_size_x'] ?? 0,
//                     'image_size_y' => $option['image_size_y'] ?? 0,
//                 ];

//                 if (!empty($option['values'])) {
//                     $formattedOption['values'] = [];
//                     foreach ($option['values'] as $value) {
//                         $formattedOption['values'][] = [
//                             'title' => $value['title'] ?? '',
//                             'sort_order' => $value['sort_order'] ?? 0,
//                             'price' => $value['price'] ?? 0,
//                             'price_type' => $value['price_type'] ?? 'fixed',
//                             'sku' => $value['sku'] ?? '',
//                             'option_type_id' => $value['option_type_id'] ?? 0
//                         ];
//                     }
//                 }

//                 $options[] = $formattedOption;
//             }
//         }

//         return $options;
//     }

//     private function buildMediaGalleryEntries(ProductDraft|VendorProduct $product, array $productData): array
//     {
//         $mediaEntries = [];

//         // Check for media_gallery_entries in product_data
//         if ($mediaGallery = data_get($productData, 'media_gallery_entries')) {
//             foreach ($mediaGallery as $media) {
//                 $entry = [
//                     'id' => $media['id'] ?? 0,
//                     'media_type' => $media['media_type'] ?? 'image',
//                     'label' => $media['label'] ?? '',
//                     'position' => $media['position'] ?? 0,
//                     'disabled' => $media['disabled'] ?? false,
//                     'types' => $media['types'] ?? [],
//                     'file' => $media['file'] ?? '',
//                 ];

//                 // Handle base64 encoded image content
//                 if (!empty($media['content'])) {
//                     // SANITIZE THE IMAGE NAME HERE
//                     $originalName = $media['content']['name'] ?? 'image.jpg';
//                     $sanitizedName = $this->sanitizeImageName($originalName);

//                     $entry['content'] = [
//                         'base64_encoded_data' => $media['content']['base64_encoded_data'] ?? '',
//                         'type' => $media['content']['type'] ?? 'image/jpeg',
//                         'name' => $sanitizedName, // Use sanitized name
//                     ];
//                 }
//                 // Alternative: handle if image data is in a different format
//                 elseif (!empty($media['base64_encoded_data'])) {
//                     $originalName = $media['name'] ?? 'image.jpg';
//                     $sanitizedName = $this->sanitizeImageName($originalName);

//                     $entry['content'] = [
//                         'base64_encoded_data' => $media['base64_encoded_data'],
//                         'type' => $media['content_type'] ?? 'image/jpeg',
//                         'name' => $sanitizedName, // Use sanitized name
//                     ];
//                 }

//                 if (!empty($media['extension_attributes']['video_content'])) {
//                     $entry['extension_attributes']['video_content'] = [
//                         'media_type' => $media['extension_attributes']['video_content']['media_type'] ?? '',
//                         'video_provider' => $media['extension_attributes']['video_content']['video_provider'] ?? '',
//                         'video_url' => $media['extension_attributes']['video_content']['video_url'] ?? '',
//                         'video_title' => $media['extension_attributes']['video_content']['video_title'] ?? '',
//                         'video_description' => $media['extension_attributes']['video_content']['video_description'] ?? '',
//                         'video_metadata' => $media['extension_attributes']['video_content']['video_metadata'] ?? ''
//                     ];
//                 }

//                 $mediaEntries[] = $entry;
//             }
//         }

//         // Also handle media_gallery field if present (alternative format)
//         elseif ($mediaGallery = data_get($productData, 'media_gallery')) {
//             foreach ($mediaGallery as $media) {
//                 $entry = [
//                     'media_type' => $media['media_type'] ?? 'image',
//                     'label' => $media['label'] ?? '',
//                     'position' => $media['position'] ?? 0,
//                     'disabled' => $media['disabled'] ?? false,
//                     'types' => $media['types'] ?? [],
//                 ];

//                 if (!empty($media['content'])) {
//                     // SANITIZE THE IMAGE NAME HERE
//                     $originalName = $media['content']['name'] ?? 'image.jpg';
//                     $sanitizedName = $this->sanitizeImageName($originalName);

//                     $entry['content'] = [
//                         'base64_encoded_data' => $media['content']['base64_encoded_data'] ?? '',
//                         'type' => $media['content']['type'] ?? 'image/jpeg',
//                         'name' => $sanitizedName, // Use sanitized name
//                     ];
//                 } elseif (!empty($media['url'])) {
//                     $entry['file'] = $media['url'];
//                 }

//                 $mediaEntries[] = $entry;
//             }
//         }

//         return $mediaEntries;
//     }

//     private function buildTierPrices(ProductDraft|VendorProduct $product, array $productData): array
//     {
//         $tierPrices = [];

//         if ($prices = data_get($productData, 'tier_prices')) {
//             foreach ($prices as $price) {
//                 $tierPrices[] = [
//                     'customer_group_id' => $price['customer_group_id'] ?? 0,
//                     'qty' => $price['qty'] ?? 0,
//                     'value' => $price['value'] ?? 0,
//                     'extension_attributes' => [
//                         'percentage_value' => $price['extension_attributes']['percentage_value'] ?? 0,
//                         'website_id' => $price['extension_attributes']['website_id'] ?? 0
//                     ]
//                 ];
//             }
//         }

//         return $tierPrices;
//     }
//     /**
//      * Resolve option ID for attribute
//      */
//     private function resolveOptionId(Vendor $vendor, string $attributeCode, string $label): int
//     {
//         $magentoService = $this->getMagentoServiceForVendor($vendor);

//         $options = Cache::remember(
//             "magento_attr_opts_{$vendor->id}_{$attributeCode}",
//             now()->addHours(24),
//             fn() => $magentoService->get("products/attributes/{$attributeCode}/options")
//         );

//         $match = collect($options)->first(
//             fn($opt) => strtolower(trim($opt['label'])) === strtolower(trim($label))
//         );

//         if (!$match) {
//             $available = collect($options)
//                 ->filter(fn($o) => $o['value'] !== '')
//                 ->pluck('label')
//                 ->implode(', ');

//             throw new \RuntimeException(
//                 "Magento attribute '{$attributeCode}': no option found for '{$label}'. " .
//                     "Available options: {$available}"
//             );
//         }

//         return (int) $match['value'];
//     }

//     /**
//      * Try to resolve option ID, fallback to raw value
//      */
//     private function tryResolveOptionId(Vendor $vendor, string $attributeCode, string $value): int|string
//     {
//         try {
//             $magentoService = $this->getMagentoServiceForVendor($vendor);

//             $options = Cache::remember(
//                 "magento_attr_opts_{$vendor->id}_{$attributeCode}",
//                 now()->addHours(24),
//                 fn() => $magentoService->get("products/attributes/{$attributeCode}/options")
//             );

//             $filteredOptions = collect($options)->filter(fn($o) => $o['value'] !== '');

//             if ($filteredOptions->isEmpty()) {
//                 return $value;
//             }

//             $match = $filteredOptions->first(
//                 fn($opt) => strtolower(trim($opt['label'])) === strtolower(trim($value))
//             );

//             return $match ? (int) $match['value'] : $value;
//         } catch (\Throwable) {
//             return $value;
//         }
//     }

//     /**
//      * Merge metadata
//      */
//     private function mergedMetadata(?VendorProduct $product, array $magentoProduct, array $extra = []): array
//     {
//         return array_merge($product?->metadata ?? [], $extra, [
//             'magento' => $magentoProduct,
//             'magento_synced_at' => now()->toIso8601String(),
//         ]);
//     }
// }

namespace App\Services\Product;

use App\Services\Integration\MagentoService;
use App\Models\Vendor\Vendor;
use Illuminate\Support\Facades\Log;
use Exception;

class ProductService
{
    protected MagentoService $magento;
    protected Vendor $vendor;

    /**
     * Factory method - Vendor ke liye service instance banaye
     */
    public static function forVendor(Vendor $vendor): self
    {
        return new self($vendor);
    }

    /**
     * Constructor - MagentoService inject karein
     */
    public function __construct(Vendor $vendor)
    {
        $this->vendor = $vendor;
        $this->magento = MagentoService::forVendor($vendor);
    }

    /**
     * ✅ MAIN FUNCTION: Product Create Karein (All Tabs Handle)
     */
    public function createProduct(array $formData): array
    {

        Log::info('ProductService::createProduct RECEIVED', [
            'has_media' => isset($productData['media']),
            'media_count' => count($productData['media'] ?? []),
            'sku' => $productData['sku'] ?? 'unknown',
            'media_sample' => $productData['media'][0] ?? null
        ]);
        try {
            // 1. Core Product Create
            $product = $this->createCoreProduct($formData);
            $sku = $product['sku'];

            // Media is now included directly in core product payload via media_gallery_entries
            // No separate API calls needed - Commented out to avoid duplicate uploads
            /*
$mediaItems = $formData['media'] ?? $formData['media_gallery'] ?? [];

if (!empty($mediaItems)) {
    Log::info('Media will be included in core product payload', [
        'sku' => $sku,
        'media_count' => count($mediaItems)
    ]);
    // Media is now handled in createCoreProduct() via buildMediaGalleryEntries()
}
*/

            // 3. Categories Assign
            // if (!empty($formData['category_ids'])) {
            //     $this->assignCategories($sku, $formData['category_ids']);
            // }

            // 4. Product Links
            if (!empty($formData['product_links'])) {
                $this->assignProductLinks($sku, $formData['product_links']);
            }

            // 5. Customizable Options
            if (!empty($formData['custom_options'])) {
                $this->addCustomOptions($sku, $formData['custom_options']);
            }

            // 6. Tier Prices
            if (!empty($formData['tier_prices'])) {
                $this->setTierPrices($sku, $formData['tier_prices']);
            }

            // 7. MSI Inventory
            if (isset($formData['inventory'])) {
                $this->assignMSIInventory($sku, $formData['inventory']);
            }

            // 8. Configurable Options
            if ($formData['type_id'] === 'configurable' && !empty($formData['configurable_options'])) {
                $this->addConfigurableOptions($sku, $formData['configurable_options']);
            }

            return [
                'success' => true,
                'message' => 'Product created successfully',
                'product' => $product,
                'sku' => $sku,
            ];
        } catch (\Exception $e) {
            Log::error('Magento Product Creation Failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'sku' => $formData['sku'] ?? 'unknown',
                'vendor_id' => $this->vendor->id ?? 'unknown',
            ]);
            throw new Exception('Product creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Step 1: Core Product Create
     */
    /**
     * Step 1: Core Product Create
     */
    protected function createCoreProduct(array $data): array
    {
        $quantity = $data['quantity'] ?? $data['qty'] ?? 0;

        // Build category links if category_ids are provided
        $categoryLinks = [];
        if (!empty($data['category_ids'])) {
            foreach ($data['category_ids'] as $index => $catId) {
                $categoryLinks[] = [
                    'position' => $index === 0 ? 0 : $index,
                    'category_id' => (string) $catId,
                    'extension_attributes' => new \stdClass() // Empty object as per Magento spec
                ];
            }
        }

        // Process media gallery entries for inclusion in core payload
        $mediaGalleryEntries = $this->buildMediaGalleryEntries($data);

        $payload = [
            'product' => [
                'sku' => $data['sku'],
                'name' => $data['name'],
                'attribute_set_id' => (int) ($data['attribute_set_id'] ?? 4),
                'price' => (float) ($data['price'] ?? 0),
                'status' => (int) ($data['status'] ?? 1),
                'visibility' => (int) ($data['visibility'] ?? 4),
                'type_id' => $data['type_id'] ?? 'simple',
                'weight' => isset($data['weight']) ? (float) $data['weight'] : null,

                'extension_attributes' => [
                    'website_ids' => $data['website_ids'] ?? [1],
                    'category_links' => $categoryLinks,
                    'stock_item' => [
                        'qty' => $quantity,
                        'is_in_stock' => $quantity > 0 ? 1 : 0,
                        'manage_stock' => isset($data['manage_stock']) ? ((int) $data['manage_stock']) : 1,
                        'use_config_manage_stock' => 0,
                        'backorders' => (int) ($data['backorders'] ?? 0),
                        'use_config_backorders' => 0,
                        'notify_stock_qty' => (int) ($data['notify_stock_qty'] ?? 0),
                        'use_config_notify_stock_qty' => 0,
                        'min_sale_qty' => (int) ($data['min_sale_qty'] ?? 1),
                        'use_config_min_sale_qty' => 0,
                        'max_sale_qty' => (int) ($data['max_sale_qty'] ?? 10000),
                        'use_config_max_sale_qty' => 0,
                        'qty_increments' => (int) ($data['qty_increments'] ?? 1),
                        'use_config_qty_increments' => 0,
                        'enable_qty_increments' => isset($data['enable_qty_increments']) ? ((int) $data['enable_qty_increments']) : 0,
                        'is_decimal_divided' => isset($data['is_decimal_divided']) ? ((int) $data['is_decimal_divided']) : 0,
                    ],
                ],

                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        // Add media gallery entries to payload if present
        if (!empty($mediaGalleryEntries)) {
            $payload['product']['media_gallery_entries'] = $mediaGalleryEntries;
        }

        // Remove weight for virtual/downloadable products
        if (in_array($data['type_id'], ['virtual', 'downloadable'])) {
            unset($payload['product']['weight']);
            unset($payload['product']['extension_attributes']['stock_item']);
        }

        return $this->magento->post('products', $payload);
    }
    /**
     * Build media gallery entries for inclusion in core product payload
     */
    /**
     * Build media gallery entries for inclusion in core product payload
     */
    protected function buildMediaGalleryEntries(array $data): array
    {
        $mediaGalleryEntries = [];

        // Get media from either key
        $mediaItems = $data['media'] ?? $data['media_gallery'] ?? [];

        if (empty($mediaItems)) {
            return [];
        }

        foreach ($mediaItems as $index => $mediaItem) {
            if (!isset($mediaItem['content']['base64_encoded_data'])) {
                continue;
            }

            // Clean base64 data
            $base64Data = $mediaItem['content']['base64_encoded_data'];
            if (strpos($base64Data, 'base64,') !== false) {
                $base64Data = substr($base64Data, strpos($base64Data, 'base64,') + 7);
            }

            // Sanitize filename - remove forbidden characters
            $originalName = $mediaItem['content']['name'] ?? 'image.jpg';
            $sanitizedName = $this->sanitizeFilename($originalName);

            // Determine types - first image gets all role types
            $types = $mediaItem['types'] ?? [];
            if (empty($types) && $index === 0) {
                $types = ['image', 'small_image', 'thumbnail', 'swatch_image'];
            }

            $mediaGalleryEntries[] = [
                'id' => 0, // 0 indicates new media entry
                'media_type' => $mediaItem['media_type'] ?? 'image',
                'label' => $mediaItem['label'] ?? pathinfo($sanitizedName, PATHINFO_FILENAME),
                'position' => (int)($mediaItem['position'] ?? $index + 1),
                'disabled' => (bool)($mediaItem['disabled'] ?? false),
                'types' => $types,
                'content' => [
                    'base64_encoded_data' => $base64Data,
                    'type' => $mediaItem['content']['type'] ?? 'image/jpeg',
                    'name' => $sanitizedName
                ]
            ];
        }

        return $mediaGalleryEntries;
    }
    // protected function createCoreProduct(array $data): array
    // {
    //     $quantity = $data['quantity'] ?? $data['qty'] ?? 0;

    //     $product = [
    //         'sku' => $data['sku'],
    //         'name' => $data['name'],
    //         'attribute_set_id' => (int) ($data['attribute_set_id'] ?? 4),
    //         'price' => (float) ($data['price'] ?? 0),
    //         'status' => (int) ($data['status'] ?? 1),
    //         'visibility' => (int) ($data['visibility'] ?? 4),
    //         'type_id' => $data['type_id'] ?? 'simple',
    //         'weight' => isset($data['weight'])
    //             ? (float) $data['weight']
    //             : null,

    //         'extension_attributes' => [
    //             'website_ids' => $data['website_ids'] ?? [1],

    //             'stock_item' => [
    //                 'qty' => $quantity,

    //                 'is_in_stock' => $quantity > 0 ? 1 : 0,

    //                 'manage_stock' => isset($data['manage_stock'])
    //                     ? ((int) $data['manage_stock'])
    //                     : 1,

    //                 'use_config_manage_stock' => 0,

    //                 'backorders' => (int) ($data['backorders'] ?? 0),

    //                 'use_config_backorders' => 0,

    //                 'notify_stock_qty' => (int) ($data['notify_stock_qty'] ?? 0),

    //                 'use_config_notify_stock_qty' => 0,

    //                 'min_sale_qty' => (int) ($data['min_sale_qty'] ?? 1),

    //                 'use_config_min_sale_qty' => 0,

    //                 'max_sale_qty' => (int) ($data['max_sale_qty'] ?? 10000),

    //                 'use_config_max_sale_qty' => 0,

    //                 'qty_increments' => (int) ($data['qty_increments'] ?? 1),

    //                 'use_config_qty_increments' => 0,

    //                 'enable_qty_increments' => isset($data['enable_qty_increments'])
    //                     ? ((int) $data['enable_qty_increments'])
    //                     : 0,

    //                 'is_decimal_divided' => isset($data['is_decimal_divided'])
    //                     ? ((int) $data['is_decimal_divided'])
    //                     : 0,
    //             ],
    //         ],

    //         'custom_attributes' => $this->buildCustomAttributes($data),
    //     ];

    //     // Remove weight & stock for virtual/downloadable
    //     if (in_array($data['type_id'], ['virtual', 'downloadable'])) {

    //         unset($product['weight']);

    //         unset($product['extension_attributes']['stock_item']);
    //     }

    //     return $this->magento->post(
    //         'products',
    //         [
    //             'product' => $product
    //         ]
    //     );
    // }

    /**
     * Custom Attributes Array Build Karein
     */
    protected function buildCustomAttributes(array $data): array
    {
        $customAttributes = [];

        // Map of fields that go to custom_attributes
        $attributeMap = [
            'description' => 'description',
            'short_description' => 'short_description',
            'meta_title' => 'meta_title',
            'meta_description' => 'meta_description',
            'meta_keyword' => 'meta_keyword',
            'url_key' => 'url_key',
            'special_price' => 'special_price',
            'special_from_date' => 'special_from_date',
            'special_to_date' => 'special_to_date',
            'cost' => 'cost',
            'msrp' => 'msrp',
            'msrp_display_actual_price_type' => 'msrp_display_actual_price_type',
            'gift_message_available' => 'gift_message_available',
            'tax_class_id' => 'tax_class_id',  // ✅ Add tax_class_id here
            'activity' => 'activity',  // Custom attribute example
            'new_attribute' => 'new_attribute',  // Any custom attribute
        ];

        foreach ($attributeMap as $inputField => $attributeCode) {
            if (isset($data[$inputField]) && $data[$inputField] !== null && $data[$inputField] !== '') {
                $value = $data[$inputField];

                // Convert boolean to '1' or '0' string
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                // Convert int to string
                elseif (is_int($value)) {
                    $value = (string) $value;
                }
                // Convert float to string with proper format
                elseif (is_float($value)) {
                    $value = number_format($value, 2, '.', '');
                }

                $customAttributes[] = [
                    'attribute_code' => $attributeCode,
                    'value' => $value,
                ];
            }
        }

        // Add dynamic attributes (any extra fields)
        if (!empty($data['dynamic_attributes'])) {
            foreach ($data['dynamic_attributes'] as $code => $value) {
                if ($value !== null && $value !== '') {
                    $customAttributes[] = [
                        'attribute_code' => $code,
                        'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    ];
                }
            }
        }

        // Generate URL key if not provided
        if (!isset($data['url_key']) && isset($data['name'])) {
            $customAttributes[] = [
                'attribute_code' => 'url_key',
                'value' => $this->generateUrlKey($data['name']),
            ];
        }

        return $customAttributes;
    }


    /**
     * Step 2: Media Upload (Images/Videos)
     */
    public function uploadMedia(string $sku, array $mediaEntry): void
    {
        // Sanitize filename before upload
        $originalName = $mediaEntry['content']['name'] ?? 'image.jpg';
        $sanitizedName = $this->sanitizeFilename($originalName);

        // Ensure the entry has the required structure for Magento 2 API
        $payload = ['entry' => [
            'media_type' => $mediaEntry['media_type'] ?? 'image',
            'label' => $mediaEntry['label'] ?? pathinfo($sanitizedName, PATHINFO_FILENAME),
            'position' => (int)($mediaEntry['position'] ?? 0),
            'disabled' => (bool)($mediaEntry['disabled'] ?? false),
            'types' => $mediaEntry['types'] ?? ['image', 'small_image', 'thumbnail'],
            'content' => [
                'base64_encoded_data' => $mediaEntry['content']['base64_encoded_data'] ?? '',
                'type' => $mediaEntry['content']['type'] ?? 'image/jpeg',
                'name' => $sanitizedName
            ]
        ]];

        Log::info('Uploading media payload', [
            'sku' => $sku,
            'media_label' => $payload['entry']['label'],
            'sanitized_filename' => $sanitizedName,
            'has_base64' => !empty($payload['entry']['content']['base64_encoded_data'])
        ]);

        $this->magento->post("products/{$sku}/media", $payload);
    }

    /**
     * Step 3: Categories Assign
     */
    public function assignCategories(string $sku, array $categoryIds): void
    {
        foreach ($categoryIds as $index => $catId) {
            $payload = [
                'category_links' => [
                    'sku' => $sku,
                    'category_id' => (string) $catId,
                    'position' => $index === 0 ? 0 : $index,
                ],
            ];

            try {
                $this->magento->post('categories/products', $payload);
            } catch (\Exception $e) {
                Log::warning("Category {$catId} assignment failed for SKU {$sku}: " . $e->getMessage(), [
                    'vendor_id' => $this->vendor->id ?? 'unknown'
                ]);
                // Continue with other categories
            }
        }
    }
    /**
     * Sanitize filename by removing forbidden characters
     * Magento 2 allows only: alphanumeric, dash, underscore, dot
     */
    protected function sanitizeFilename(string $filename): string
    {
        // Get file extension
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        // Remove all special characters except alphanumeric, dash, underscore
        $basename = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $basename);

        // Replace multiple consecutive dashes with single dash
        $basename = preg_replace('/-+/', '-', $basename);

        // Remove leading/trailing dashes
        $basename = trim($basename, '-');

        // Ensure basename is not empty
        if (empty($basename)) {
            $basename = 'image';
        }

        // Generate unique filename with timestamp to prevent conflicts
        $basename = $basename . '_' . time() . '_' . rand(100, 999);

        // Rebuild filename with extension
        if (!empty($extension)) {
            // Validate extension is allowed
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower($extension);
            if (!in_array($extension, $allowedExtensions)) {
                $extension = 'jpg';
            }
            return $basename . '.' . $extension;
        }

        return $basename . '.jpg';
    }
    /**
     * Step 4: Product Links (Related/Up-Sell/Cross-Sell)
     */
    public function assignProductLinks(string $sku, array $links): void
    {
        $typeMap = [
            'related' => 'related',
            'up-sell' => 'upsell',
            'upsell' => 'upsell',
            'cross-sell' => 'crosssell',
            'crosssell' => 'crosssell',
        ];

        $items = [];
        foreach ($links as $index => $link) {
            $apiType = $typeMap[$link['link_type'] ?? 'related'] ?? 'related';

            // ✅ FIX: Support both field names
            $linkedSku = $link['linked_sku'] ?? $link['linked_product_sku'] ?? null;
            $linkedType = $link['linked_type'] ?? $link['linked_product_type'] ?? 'simple';

            if (!$linkedSku) {
                continue; // Skip if no SKU
            }

            $items[] = [
                'sku' => $sku,
                'link_type' => $apiType,
                'linked_product_sku' => $linkedSku,
                'linked_product_type' => $linkedType,
                'position' => $index + 1,
                'extension_attributes' => ['qty' => 0],
            ];
        }

        if (empty($items)) return;

        $this->magento->post("products/{$sku}/links", ['items' => $items]);
    }

    /**
     * Step 5: Customizable Options
     */
    public function addCustomOptions(string $sku, array $options): void
    {
        $validTypes = [
            'drop_down',
            'radio',
            'checkbox',
            'multiple',
            'field',
            'area',
            'file',
            'date',
            'date_time',
            'time',
        ];

        foreach ($options as $opt) {

            if (!in_array($opt['type'], $validTypes)) {
                continue;
            }

            $entry = [
                'product_sku' => $sku,
                'title'       => $opt['title'],
                'type'        => $opt['type'],
                'is_require'  => (bool) ($opt['is_required'] ?? false),
                'sort_order'  => (int) ($opt['sort_order'] ?? 0),
            ];

            // Select-type options
            if (
                in_array($opt['type'], ['drop_down', 'radio', 'checkbox', 'multiple']) &&
                !empty($opt['values'])
            ) {

                $entry['values'] = array_map(function ($v) {
                    return [
                        'title'       => $v['title'],
                        'price'       => (float) ($v['price'] ?? 0),
                        'price_type'  => $v['price_type'] ?? 'fixed',
                        'sku'         => $v['sku'] ?? '',
                        'sort_order'  => (int) ($v['sort_order'] ?? 0),
                    ];
                }, $opt['values']);
            } else {
                // Non-select types
                $entry['price'] = (float) ($opt['price'] ?? 0);
                $entry['price_type'] = $opt['price_type'] ?? 'fixed';
            }

            $this->magento->post(
                'products/options',
                [
                    'option' => $entry
                ]
            );
        }
    }

    /**
     * Step 6: Tier Prices
     */
    // public function setTierPrices(string $sku, array $tiers): void
    // {
    //     $prices = array_map(fn($t) => [
    //         'sku' => $sku,
    //         'price' => (float) $t['price'],
    //         'price_type' => $t['price_type'] ?? 'fixed',
    //         'customer_group_id' => (int) ($t['customer_group_id'] ?? 0),
    //         'qty' => (int) $t['qty'],
    //     ], $tiers);

    //     if (empty($prices)) return;

    //     // $this->magento->post("products/{$sku}/tier-prices", ['prices' => $prices]);
    //     $this->magento->post("products/tier-prices", ['prices' => $prices]);
    // }

    public function setTierPrices(string $sku, array $tiers): void
    {
        $prices = array_map(fn($t) => [
            'sku' => $sku,

            'price' => (float) $t['price'],

            // Magento expects fixed | discount
            'price_type' => $t['price_type'] ?? 'fixed',

            // Magento expects customer_group NOT customer_group_id
            'customer_group' => $t['customer_group'] ?? 'ALL GROUPS',

            // Magento expects quantity NOT qty
            'quantity' => (float) ($t['quantity'] ?? 1),

            // Optional but recommended
            'website_id' => (int) ($t['website_id'] ?? 0),

        ], $tiers);

        if (empty($prices)) {
            return;
        }

        $this->magento->post(
            "products/tier-prices",
            [
                'prices' => $prices
            ]
        );
    }


    /**
     * Step 7: MSI Inventory (Modern Stock)
     */
    public function assignMSIInventory(string $sku, array $inventory): void
    {
        $sourceItems = [[
            'sku' => $sku,
            'source_code' => $inventory['source_code'] ?? 'default',
            'quantity' => (float) ($inventory['quantity'] ?? 0),
            'status' => (int) ($inventory['status'] ?? 1),
        ]];

        $this->magento->post('inventory/source-items', ['sourceItems' => $sourceItems]);
    }

    /**
     * Step 8: Configurable Product Options
     */
    public function addConfigurableOptions(string $sku, array $configOptions): void
    {
        $formatted = array_map(fn($opt) => [
            'attribute_id' => (int) $opt['attribute_id'],
            'label' => $opt['label'],
            'position' => (int) ($opt['position'] ?? 0),
            'is_use_default' => (bool) ($opt['is_use_default'] ?? false),
            'values' => array_map(fn($v) => ['value_index' => (int) $v['value_index']], $opt['values']),
        ], $configOptions);

        if (empty($formatted)) return;

        // Configurable options endpoint raw array expect karta hai
        $this->magento->request('POST', "configurable-products/{$sku}/options", $formatted);
    }

    /**
     * Helper: Product Fetch by SKU
     */
    public function getProductBySku(string $sku): ?array
    {
        try {
            return $this->magento->get("products/{$sku}");
        } catch (\Exception $e) {
            Log::warning("Failed to fetch product {$sku}: " . $e->getMessage(), [
                'vendor_id' => $this->vendor->id ?? 'unknown'
            ]);
            return null;
        }
    }

    /**
     * Helper: Product Update (Partial)
     */
    /**
     * Helper: Product Update (Partial)
     */
    public function updateProduct(string $sku, array $data): array
    {
        $payload = [
            'product' => [
                'custom_attributes' => $this->buildCustomAttributes($data),
            ],
        ];

        $updatable = ['price', 'status', 'visibility', 'weight', 'tax_class_id'];
        foreach ($updatable as $field) {
            if (isset($data[$field])) {
                $payload['product'][$field] = $data[$field];
            }
        }

        // Add media gallery entries if updating media
        $mediaGalleryEntries = $this->buildMediaGalleryEntries($data);
        if (!empty($mediaGalleryEntries)) {
            $payload['product']['media_gallery_entries'] = $mediaGalleryEntries;
        }

        return $this->magento->put("products/{$sku}", $payload);
    }

    /**
     * Fetch all products from Magento
     */
    public function fetchAllProducts(array $filters = []): array
    {
        try {

            $query = [];

            // Pagination
            $query['searchCriteria[currentPage]'] =
                $filters['page'] ?? 1;

            $query['searchCriteria[pageSize]'] =
                $filters['per_page'] ?? 20;

            // Optional search by SKU
            if (!empty($filters['sku'])) {

                $query['searchCriteria[filter_groups][0][filters][0][field]'] = 'sku';

                $query['searchCriteria[filter_groups][0][filters][0][value]'] = '%' . $filters['sku'] . '%';

                $query['searchCriteria[filter_groups][0][filters][0][condition_type]'] = 'like';
            }

            // Optional search by name
            if (!empty($filters['name'])) {

                $query['searchCriteria[filter_groups][1][filters][0][field]'] = 'name';

                $query['searchCriteria[filter_groups][1][filters][0][value]'] = '%' . $filters['name'] . '%';

                $query['searchCriteria[filter_groups][1][filters][0][condition_type]'] = 'like';
            }

            // Optional status filter
            if (isset($filters['status'])) {

                $query['searchCriteria[filter_groups][2][filters][0][field]'] = 'status';

                $query['searchCriteria[filter_groups][2][filters][0][value]'] = $filters['status'];

                $query['searchCriteria[filter_groups][2][filters][0][condition_type]'] = 'eq';
            }

            // Sorting
            $query['searchCriteria[sortOrders][0][field]'] =
                $filters['sort_by'] ?? 'entity_id';

            $query['searchCriteria[sortOrders][0][direction]'] =
                $filters['sort_order'] ?? 'DESC';

            $endpoint = 'products?' . http_build_query($query);

            Log::info('Fetching Magento Products', [
                'endpoint' => $endpoint,
                'vendor_id' => $this->vendor->id,
            ]);

            return $this->magento->get($endpoint);
        } catch (\Exception $e) {

            Log::error('Magento fetchAllMagentoProducts failed', [
                'message' => $e->getMessage(),
                'vendor_id' => $this->vendor->id,
            ]);

            throw new Exception(
                'Failed to fetch Magento products: ' . $e->getMessage()
            );
        }
    }


    /**
     * ✅ CREATE CONFIGURABLE PRODUCT (Full Flow)
     * Execution Order: Children → Parent → Link Options → Link Children → Post-Processing
     * Uses EXACT endpoint: POST /rest/V1/products
     * Uses EXACT payload format from .md file
     */
    public function createConfigurableProduct(array $formData): array
    {
        try {
            $parentSku = $formData['sku'];
            $configOptions = $formData['configurable_options'] ?? [];
            $childVariants = $formData['child_variants'] ?? [];

            Log::info('Creating Configurable Product', [
                'parent_sku' => $parentSku,
                'children_count' => count($childVariants),
                'config_options_count' => count($configOptions)
            ]);

            // ─────────────────────────────────────────────────────────────
            // STEP 1: Create Child Variants (Simple Products) FIRST
            // Children MUST have: type_id='simple', visibility=1
            // ─────────────────────────────────────────────────────────────
            $childSkus = [];
            foreach ($childVariants as $childData) {
                $childSku = $this->createConfigurableChild($parentSku, $childData);
                $childSkus[] = $childSku;
            }

            // ─────────────────────────────────────────────────────────────
            // STEP 2: Create Parent Configurable Product
            // Uses EXACT payload structure from .md file
            // Endpoint: POST /rest/V1/products (DO NOT CHANGE)
            // ─────────────────────────────────────────────────────────────
            $parentPayload = $this->buildConfigurableParentPayload($formData);
            $parent = $this->magento->post('products', $parentPayload);

            // ─────────────────────────────────────────────────────────────
            // STEP 3: Link Configurable Options (Super Attributes)
            // Endpoint: POST /rest/V1/configurable-products/{sku}/options
            // Payload: RAW ARRAY format (no {"option": {...}} wrapper)
            // ─────────────────────────────────────────────────────────────
            if (!empty($configOptions)) {
                $this->linkConfigurableOptions($parentSku, $configOptions);
            }

            // ─────────────────────────────────────────────────────────────
            // STEP 4: Link Each Child to Parent
            // Endpoint: POST /rest/V1/configurable-products/{parentSku}/child
            // ─────────────────────────────────────────────────────────────
            foreach ($childSkus as $childSku) {
                $this->linkChildToParent($parentSku, $childSku);
            }

            // ─────────────────────────────────────────────────────────────
            // STEP 5: Post-Processing (Categories, Links, Options, MSI)
            // Reuse existing methods where possible
            // ─────────────────────────────────────────────────────────────
            if (!empty($formData['category_ids'])) {
                $this->assignCategories($parentSku, $formData['category_ids']);
            }

            if (!empty($formData['product_links'])) {
                $this->assignProductLinks($parentSku, $formData['product_links']);
            }

            if (!empty($formData['custom_options'])) {
                $this->addCustomOptions($parentSku, $formData['custom_options']);
            }

            if (!empty($formData['tier_prices'])) {
                $this->setTierPrices($parentSku, $formData['tier_prices']);
            }

            // MSI Inventory: Assign to CHILDREN only (parent holds no physical stock)
            if (isset($formData['inventory']) && !empty($childSkus)) {
                foreach ($childSkus as $childSku) {
                    $this->assignMSIInventory($childSku, $formData['inventory']);
                }
            }

            return [
                'success' => true,
                'message' => 'Configurable product created successfully',
                'parent_sku' => $parentSku,
                'child_skus' => $childSkus,
                'product' => $parent,
            ];
        } catch (\Exception $e) {
            Log::error('Configurable Product Creation Failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'sku' => $formData['sku'] ?? 'unknown',
                'vendor_id' => $this->vendor->id ?? 'unknown',
            ]);
            throw new Exception('Configurable product creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a single child variant (simple product) for configurable parent
     * ⚠️ Critical: visibility=1 (not visible individually), type_id='simple'
     */
    protected function createConfigurableChild(string $parentSku, array $childData): string
    {
        $childSku = $childData['sku'];

        // Build configurable attribute values (color, size, etc.)
        // These go in custom_attributes with attribute_code + value (value_index as string)
        $configAttributes = [];
        if (!empty($childData['configurable_attributes'])) {
            foreach ($childData['configurable_attributes'] as $attrCode => $value) {
                $configAttributes[] = [
                    'attribute_code' => $attrCode,
                    'value' => (string) $value, // value_index must be string
                ];
            }
        }

        // Merge with other custom attributes (description, SEO, etc.)
        $allCustomAttributes = array_merge(
            $this->buildCustomAttributes($childData),
            $configAttributes
        );

        // Build media gallery entries (included in core payload per .md)
        $mediaGalleryEntries = $this->buildMediaGalleryEntries($childData);

        $quantity = $childData['quantity'] ?? $childData['qty'] ?? 0;

        // ─────────────────────────────────────────────────────────────
        // EXACT PAYLOAD STRUCTURE FOR CHILD (Simple Product)
        // Endpoint: POST /rest/V1/products (DO NOT CHANGE)
        // ─────────────────────────────────────────────────────────────
        $payload = [
            'product' => [
                'sku' => $childSku,
                'name' => $childData['name'] ?? "{$parentSku} Variant",
                'attribute_set_id' => (int) ($childData['attribute_set_id'] ?? 4),
                'price' => (float) ($childData['price'] ?? 0),
                'status' => 1,                              // Children enabled
                'visibility' => 1,                          // ⚠️ NOT visible individually (critical!)
                'type_id' => 'simple',                      // ⚠️ MUST be 'simple' for children
                'weight' => isset($childData['weight']) ? (float) $childData['weight'] : null,

                'extension_attributes' => [
                    'website_ids' => $childData['website_ids'] ?? [1],
                    'stock_item' => [
                        'qty' => (int) $quantity,
                        'is_in_stock' => $quantity > 0 ? 1 : 0,
                        'manage_stock' => isset($childData['manage_stock']) ? (int) $childData['manage_stock'] : 1,
                        'use_config_manage_stock' => 0,
                        'backorders' => (int) ($childData['backorders'] ?? 0),
                        'use_config_backorders' => 0,
                    ],
                ],

                'custom_attributes' => $allCustomAttributes,
            ],
        ];

        // Add media gallery if present (per .md: included in core payload)
        if (!empty($mediaGalleryEntries)) {
            $payload['product']['media_gallery_entries'] = $mediaGalleryEntries;
        }

        // Remove weight/stock for virtual products
        if (in_array($childData['type_id'] ?? 'simple', ['virtual', 'downloadable'])) {
            unset($payload['product']['weight']);
            unset($payload['product']['extension_attributes']['stock_item']);
        }

        $this->magento->post('products', $payload);

        Log::info('Configurable child created', ['sku' => $childSku, 'parent' => $parentSku]);

        return $childSku;
    }

    /**
     * Build payload for configurable PARENT product
     * Uses EXACT structure from .md file with configurable_product_options in extension_attributes
     */
    protected function buildConfigurableParentPayload(array $formData): array
    {
        // Build category links
        $categoryLinks = [];
        if (!empty($formData['category_ids'])) {
            foreach ($formData['category_ids'] as $index => $catId) {
                $categoryLinks[] = [
                    'position' => $index === 0 ? 0 : $index,
                    'category_id' => (string) $catId,
                    'extension_attributes' => new \stdClass()
                ];
            }
        }

        // ─────────────────────────────────────────────────────────────
        // Build configurable_product_options (RAW ARRAY format)
        // ⚠️ Must be inside extension_attributes, NOT wrapped in {"options": [...]}
        // ⚠️ attribute_id = EAV DB integer ID (not attribute_code string)
        // ⚠️ value_index = dropdown option integer ID (not label string)
        // ─────────────────────────────────────────────────────────────
        $configurableOptions = [];
        if (!empty($formData['configurable_options'])) {
            foreach ($formData['configurable_options'] as $opt) {
                $configurableOptions[] = [
                    'attribute_id' => (int) $opt['attribute_id'],  // EAV ID as integer
                    'label' => $opt['label'] ?? '',
                    'position' => (int) ($opt['position'] ?? 0),
                    'values' => array_map(fn($v) => [
                        'value_index' => (int) $v['value_index'],  // Option ID as integer
                    ], $opt['values'] ?? []),
                ];
            }
        }

        // Build media gallery entries (included in core payload per .md)
        $mediaGalleryEntries = $this->buildMediaGalleryEntries($formData);

        // ─────────────────────────────────────────────────────────────
        // EXACT PAYLOAD STRUCTURE FOR PARENT (Configurable Product)
        // Endpoint: POST /rest/V1/products (DO NOT CHANGE)
        // ─────────────────────────────────────────────────────────────
        $payload = [
            'product' => [
                'sku' => $formData['sku'],
                'name' => $formData['name'],
                'attribute_set_id' => (int) ($formData['attribute_set_id'] ?? 4),
                'price' => (float) ($formData['price'] ?? 0),
                'status' => (int) ($formData['status'] ?? 1),
                'visibility' => 4,                          // ⚠️ Visible in catalog & search
                'type_id' => 'configurable',                // ⚠️ MUST be 'configurable'
                'weight' => 0,                              // Configurable typically has no weight

                'extension_attributes' => [
                    'website_ids' => $formData['website_ids'] ?? [1],
                    'category_links' => $categoryLinks,
                    // ⚠️ RAW ARRAY of configurable options (critical format)
                    'configurable_product_options' => $configurableOptions,
                    // Parent stock: typically managed at child level
                    'stock_item' => [
                        'qty' => 0,
                        'is_in_stock' => true,              // Parent shows in stock if children available
                        'manage_stock' => false,            // Don't manage at parent level
                    ],
                ],

                'custom_attributes' => $this->buildCustomAttributes($formData),
            ],
        ];

        // Add media gallery if present (per .md: included in core payload)
        if (!empty($mediaGalleryEntries)) {
            $payload['product']['media_gallery_entries'] = $mediaGalleryEntries;
        }

        return $payload;
    }

    /**
     * Link configurable options (super attributes) to parent product
     * Endpoint: POST /rest/V1/configurable-products/{sku}/options
     * ⚠️ Payload: RAW ARRAY - no {"option": {...}} wrapper for outer structure
     */
    protected function linkConfigurableOptions(string $sku, array $configOptions): void
    {
        if (empty($configOptions)) {
            return;
        }

        // Format each option according to Magento API spec
        $formatted = array_map(fn($opt) => [
            'attribute_id' => (int) $opt['attribute_id'],
            'label' => $opt['label'] ?? '',
            'position' => (int) ($opt['position'] ?? 0),
            'is_use_default' => (bool) ($opt['is_use_default'] ?? false),
            'values' => array_map(fn($v) => [
                'value_index' => (int) $v['value_index'],
            ], $opt['values'] ?? []),
        ], $configOptions);

        Log::info('Linking configurable options', [
            'parent_sku' => $sku,
            'options_count' => count($formatted)
        ]);

        // ⚠️ Magento expects RAW ARRAY body for this endpoint
        // Your MagentoService->request() should handle this
        $this->magento->request('POST', "configurable-products/{$sku}/options", $formatted);
    }

    /**
     * Link a child simple product to parent configurable product
     * Endpoint: POST /rest/V1/configurable-products/{parentSku}/child
     */
    protected function linkChildToParent(string $parentSku, string $childSku): void
    {
        $payload = ['childSku' => $childSku];

        Log::info('Linking child to parent', [
            'parent_sku' => $parentSku,
            'child_sku' => $childSku
        ]);

        $this->magento->post("configurable-products/{$parentSku}/child", $payload);
    }

    /**
     * 🔧 UTILITY: Generate child variant combinations from configurable attributes
     * Use this for "Create Configurations" wizard logic (bulk variant generation)
     * 
     * Input example:
     * [
     *   'color' => [52 => 'Red', 53 => 'Blue'],
     *   'size' => [16 => 'S', 17 => 'M']
     * ]
     * 
     * Output: Array of variant data for createConfigurableChild()
     */
    public function generateConfigurableVariants(array $attributeValues, array $baseData): array
    {
        $combinations = $this->getAttributeCombinations($attributeValues);
        $variants = [];

        foreach ($combinations as $combination) {
            // Generate SKU: PARENT-COLOR-SIZE format
            $skuParts = [$baseData['parent_sku']];
            $configAttrs = [];

            foreach ($combination as $attrCode => $valueIndex) {
                $skuParts[] = strtoupper($attrCode) . '-' . $valueIndex;
                $configAttrs[$attrCode] = $valueIndex;
            }

            $variants[] = [
                'sku' => implode('-', $skuParts),
                'name' => $baseData['name'] . ' ' . implode(' ', array_values($combination)),
                'price' => $baseData['price'] ?? 0,
                'quantity' => $baseData['quantity'] ?? 0,
                'configurable_attributes' => $configAttrs,
                'attribute_set_id' => $baseData['attribute_set_id'] ?? 4,
                'website_ids' => $baseData['website_ids'] ?? [1],
                'media' => $baseData['media'] ?? [],
            ];
        }

        return $variants;
    }

    /**
     * Helper: Generate all combinations from attribute values (recursive)
     * Input: ['color' => [52, 53], 'size' => [16, 17]]
     * Output: [[color=>52,size=>16], [color=>52,size=>17], [color=>53,size=>16], [color=>53,size=>17]]
     */
    protected function getAttributeCombinations(array $arrays, $i = 0): array
    {
        if (!isset($arrays[$i])) {
            return [[]];
        }

        $combinations = [];
        $currentArray = $arrays[$i];
        $remainingCombinations = $this->getAttributeCombinations($arrays, $i + 1);

        foreach ($currentArray as $key => $value) {
            foreach ($remainingCombinations as $combination) {
                $combinations[] = array_merge([$key => $value], $combination);
            }
        }

        return $combinations;
    }
}
