<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Product\CreateProductRequest;
use App\Http\Requests\Api\Vendor\Product\UpdateProductRequest;
use App\Models\Product\VendorProduct;
use App\Services\Product\ProductService;
use App\Services\Vendor\VendorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    protected VendorService $vendorService;
    protected ProductService $ProductService;

    public function __construct(VendorService $vendorService)
    {
        $this->vendorService = $vendorService;
    }

    /**
     * Get vendor by UUID
     */
    protected function getVendor(string $vendorUuid)
    {
        $vendor = $this->vendorService->getVendorByUuid($vendorUuid);
        if (!$vendor) {
            abort(404, 'Vendor not found');
        }
        return $vendor;
    }

    /**
     * Initialize Magento service for vendor
     */
    protected function initMagentoService($vendor): ProductService
    {
        return ProductService::forVendor($vendor);
    }

    /**
     * GET /api/vendor/{vendor_uuid}/products
     * List all products (READ FROM LOCAL DB ONLY)
     */
    public function index(Request $request, string $vendorUuid)
    {
        $vendor = $this->getVendor($vendorUuid);

        $query = VendorProduct::where('vendor_id', $vendor->id);

        // Apply filters
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('sku', 'LIKE', "%{$search}%");
            });
        }

        if ($request->has('type_id')) {
            $query->where('type_id', $request->type_id);
        }

        if ($request->has('sync_status')) {
            $query->where('sync_status', $request->sync_status);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }

        if ($request->has('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $perPage = $request->get('per_page', 15);
        $products = $query->paginate($perPage);

        // Add complete product data as attribute
        $products->getCollection()->transform(function ($product) {
            $product->product_data = $product->complete_product_data;
            return $product;
        });

        return response()->json([
            'success' => true,
            'data' => $products,
            'message' => 'Products retrieved successfully'
        ]);
    }

    /**
     * GET /api/vendor/{vendor_uuid}/products/{product_uuid}
     * Get single product (READ FROM LOCAL DB ONLY)
     */
    public function show(string $vendorUuid, string $productUuid)
    {
        $vendor = $this->getVendor($vendorUuid);

        $product = VendorProduct::where('vendor_id', $vendor->id)
            ->where('uuid', $productUuid)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Add complete product data
        $product->product_data = $product->complete_product_data;

        return response()->json([
            'success' => true,
            'data' => $product,
            'message' => 'Product retrieved successfully'
        ]);
    }

    /**
     * POST /api/vendor/{vendor_uuid}/products
     * Create product (WRITE TO MAGENTO API + SAVE TO LOCAL DB)
     */
    // public function store(CreateProductRequest $request, string $vendorUuid)
    // {
    //     Log::info($request . "NAEEM");
    //     $vendor = $this->getVendor($vendorUuid);
    //     $magentoService = $this->initMagentoService($vendor);

    //     DB::beginTransaction();

    //     try {
    //         // Build complete payload for Magento
    //         $magentoPayload = $this->buildMagentoPayload($request->validated());

    //         // Create product in Magento
    //         $magentoResponse = $magentoService->createProduct($magentoPayload);

    //         if (!$magentoResponse['success']) {
    //             throw new \Exception($magentoResponse['message'] ?? 'Magento product creation failed');
    //         }

    //         // Save to local database
    //         $product = VendorProduct::create([
    //             'vendor_id' => $vendor->id,
    //             'vendor_store_id' => $vendor->store_id ?? null,
    //             'magento_product_id' => $magentoResponse['product']['id'] ?? null,
    //             'magento_sku' => $magentoResponse['sku'],
    //             'sku' => $request->sku,
    //             'name' => $request->name,
    //             'type_id' => $request->type_id,
    //             'attribute_set_id' => $request->attribute_set_id,
    //             'price' => $request->price,
    //             'quantity' => $request->quantity,
    //             'status' => $request->status ?? true,
    //             'full_product_data' => $magentoPayload,
    //             'sync_status' => 'synced',
    //             'last_synced_at' => now(),
    //             'metadata' => [
    //                 'created_from' => 'api',
    //                 'magento_response' => $magentoResponse,
    //                 'ip_address' => $request->ip(),
    //             ],
    //         ]);

    //         DB::commit();

    //         $product->product_data = $product->complete_product_data;

    //         return response()->json([
    //             'success' => true,
    //             'data' => $product,
    //             'magento_response' => $magentoResponse,
    //             'message' => 'Product created successfully and synced to Magento'
    //         ], 201);

    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         Log::error('Product creation failed', [
    //             'vendor_uuid' => $vendorUuid,
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Product creation failed: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }
    // public function store(CreateProductRequest $request, string $vendorUuid)
    // {
    //     $vendor = $this->getVendor($vendorUuid);

    //     $generatedSku = Str::upper(
    //         Str::slug($request->sku, '-')
    //     );

    //     $request->merge([
    //         'sku' => $generatedSku
    //     ]);

    //     // Get the vendor store ID
    //     $vendorStoreId = null;

    //     // Method 1: If vendor has direct store relationship
    //     if (isset($vendor->store)) {
    //         $vendorStoreId = $vendor->store->id;
    //     }

    //     // Method 2: If you need to find by store UUID from request
    //     if ($request->has('store_uuid')) {
    //         $store = \App\Models\Vendor\VendorStore::where('uuid', $request->store_uuid)
    //             ->where('vendor_id', $vendor->id)
    //             ->first();
    //         if ($store) {
    //             $vendorStoreId = $store->id;
    //         }
    //     }

    //     // Method 3: Get first available store for vendor
    //     if (!$vendorStoreId) {
    //         $store = \App\Models\Vendor\VendorStore::where('vendor_id', $vendor->id)->first();
    //         if ($store) {
    //             $vendorStoreId = $store->id;
    //         }
    //     }

    //     // If still no store, throw error or use default
    //     if (!$vendorStoreId) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'No store found for this vendor. Please create a store first.'
    //         ], 400);
    //     }

    //     $magentoService = $this->initMagentoService($vendor);

    //     DB::beginTransaction();

    //     try {
    //         // Build payload
    //         $magentoPayload = $this->buildMagentoPayload($request->validated());
    //         Log::info('PAYLOAD FOR MAGENTO', [
    //             'payload' => $magentoPayload
    //         ]);
    //         // Create product in Magento
    //         $magentoResponse = $magentoService->createProduct($magentoPayload);

    //         if (!$magentoResponse['success']) {
    //             throw new \Exception($magentoResponse['message'] ?? 'Magento product creation failed');
    //         }

    //         // Save to local database with vendor_store_id
    //         $product = VendorProduct::create([
    //             'vendor_id' => $vendor->id,
    //             'vendor_store_id' => $vendorStoreId, // ← Now this has value
    //             'magento_product_id' => $magentoResponse['product']['id'] ?? null,
    //             'magento_sku' => $magentoResponse['sku'],
    //             'sku' => $generatedSku,
    //             'name' => $request->name,
    //             'type_id' => $request->type_id,
    //             'attribute_set_id' => $request->attribute_set_id,
    //             'price' => $request->price,
    //             'quantity' => $request->quantity ?? 0,
    //             'status' => $request->status ?? true,
    //             'full_product_data' => $magentoPayload,
    //             'sync_status' => 'synced',
    //             'last_synced_at' => now(),
    //             'metadata' => json_encode([  // Make sure to json_encode
    //                 'created_from' => 'api',
    //                 'magento_response' => $magentoResponse,
    //                 'ip_address' => $request->ip(),
    //             ]),
    //         ]);

    //         DB::commit();

    //         $product->product_data = $product->complete_product_data;

    //         return response()->json([
    //             'success' => true,
    //             'data' => $product,
    //             'magento_response' => $magentoResponse,
    //             'message' => 'Product created successfully and synced to Magento'
    //         ], 201);
    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         Log::error('Product creation failed', [
    //             'vendor_uuid' => $vendorUuid,
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Product creation failed: ' . $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function store(CreateProductRequest $request, string $vendorUuid)

    {

        $vendor = $this->getVendor($vendorUuid);

        $generatedSku = Str::upper(
            Str::slug($request->sku, '-')
        );

        $request->merge([
            'sku' => $generatedSku
        ]);

        // Get the vendor store ID
        $vendorStoreId = null;

        // Method 1: If vendor has direct store relationship
        if (isset($vendor->store)) {
            $vendorStoreId = $vendor->store->id;
        }

        // Method 2: If you need to find by store UUID from request
        if ($request->has('store_uuid')) {
            $store = \App\Models\Vendor\VendorStore::where('uuid', $request->store_uuid)
                ->where('vendor_id', $vendor->id)
                ->first();
            if ($store) {
                $vendorStoreId = $store->id;
            }
        }

        // Method 3: Get first available store for vendor
        if (!$vendorStoreId) {
            $store = \App\Models\Vendor\VendorStore::where('vendor_id', $vendor->id)->first();
            if ($store) {
                $vendorStoreId = $store->id;
            }
        }

        // If still no store, throw error or use default
        if (!$vendorStoreId) {
            return response()->json([
                'success' => false,
                'message' => 'No store found for this vendor. Please create a store first.'
            ], 400);
        }

        $magentoService = $this->initMagentoService($vendor);

        DB::beginTransaction();

        try {
            // Build payload
            $validatedData = $request->validated();

            // ADD THIS LOG - What validated data contains
            Log::info('VALIDATED DATA BEFORE BUILDING PAYLOAD', [
                'has_media_gallery' => isset($validatedData['media_gallery']),
                'media_gallery_count' => count($validatedData['media_gallery'] ?? []),
                'media_gallery_keys' => array_keys($validatedData['media_gallery'] ?? []),
                'first_media_item' => $validatedData['media_gallery'][0] ?? null,
            ]);

            $magentoPayload = $this->buildMagentoPayload($validatedData);

            // ADD THIS LOG - What's being sent to ProductService
            Log::info('PAYLOAD BEING SENT TO ProductService::createProduct', [
                'has_media' => isset($magentoPayload['media']),
                'media_count' => count($magentoPayload['media'] ?? []),
                'media_items' => $magentoPayload['media'] ?? [],
                'full_payload_keys' => array_keys($magentoPayload)
            ]);

            // Create product in Magento
            $magentoPayload = $this->buildMagentoPayload($validatedData);

            // CRITICAL FIX: Ensure media key matches what ProductService expects
            if (isset($magentoPayload['media']) && !isset($magentoPayload['media'])) {
                $magentoPayload['media'] = $magentoPayload['media'];
            }

            // ADD THIS: Log what's being sent to ProductService
            Log::info('PAYLOAD BEING SENT TO ProductService::createProduct', [
                'has_media' => isset($magentoPayload['media']),
                'media_count' => count($magentoPayload['media'] ?? []),
                'sku' => $magentoPayload['sku'],
                'media_keys' => array_keys($magentoPayload['media'] ?? [])
            ]);

            $magentoResponse = $magentoService->createProduct($magentoPayload);

            if (!$magentoResponse['success']) {
                throw new \Exception($magentoResponse['message'] ?? 'Magento product creation failed');
            }

            // Save to local database with vendor_store_id
            $product = VendorProduct::create([
                'vendor_id' => $vendor->id,
                'vendor_store_id' => $vendorStoreId,
                'magento_product_id' => $magentoResponse['product']['id'] ?? null,
                'magento_sku' => $magentoResponse['sku'],
                'sku' => $generatedSku,
                'name' => $request->name,
                'type_id' => $request->type_id,
                'attribute_set_id' => $request->attribute_set_id,
                'price' => $request->price,
                'quantity' => $request->quantity ?? 0,
                'status' => $request->status ?? true,
                'full_product_data' => $magentoPayload,
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'metadata' => json_encode([
                    'created_from' => 'api',
                    'magento_response' => $magentoResponse,
                    'ip_address' => $request->ip(),
                ]),
            ]);

            DB::commit();

            $product->product_data = $product->complete_product_data;

            return response()->json([
                'success' => true,
                'data' => $product,
                'magento_response' => $magentoResponse,
                'message' => 'Product created successfully and synced to Magento'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Product creation failed', [
                'vendor_uuid' => $vendorUuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Product creation failed: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * PUT /api/vendor/{vendor_uuid}/products/{product_uuid}
     * Update product (WRITE TO MAGENTO API + UPDATE LOCAL DB)
     */
    public function update(UpdateProductRequest $request, string $vendorUuid, string $productUuid)
    {
        $vendor = $this->getVendor($vendorUuid);

        $product = VendorProduct::where('vendor_id', $vendor->id)
            ->where('uuid', $productUuid)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        DB::beginTransaction();

        try {
            // Update sync status to updating
            $product->update(['sync_status' => 'updating']);

            // Initialize Magento service
            $magentoService = $this->initMagentoService($vendor);

            // Prepare update payload
            $updateData = $this->buildUpdatePayload($request->validated(), $product->full_product_data ?? []);

            // Update in Magento
            if (!empty($updateData)) {
                $magentoService->updateProduct($product->sku, $updateData);
            }

            // Handle additional operations (categories, media, etc.)
            $this->handleAdditionalUpdates($magentoService, $product->sku, $request->validated());

            // Update local database
            $updateFields = $this->prepareLocalUpdateFields($request->validated());

            // Merge with existing full_product_data
            $existingData = $product->full_product_data ?? [];
            $updatedFullData = array_merge($existingData, $this->buildMagentoPayload($request->validated()));

            $updateFields['full_product_data'] = $updatedFullData;
            $updateFields['sync_status'] = 'synced';
            $updateFields['last_synced_at'] = now();

            $product->update($updateFields);

            DB::commit();

            $product->product_data = $product->complete_product_data;

            return response()->json([
                'success' => true,
                'data' => $product,
                'message' => 'Product updated successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            $product->update(['sync_status' => 'failed', 'sync_errors' => ['update_error' => $e->getMessage()]]);

            Log::error('Product update failed', [
                'product_uuid' => $productUuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Product update failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/vendor/{vendor_uuid}/products/{product_uuid}
     * Delete product (DELETE FROM MAGENTO + SOFT DELETE FROM LOCAL DB)
     */
    public function destroy(string $vendorUuid, string $productUuid)
    {
        $vendor = $this->getVendor($vendorUuid);

        $product = VendorProduct::where('vendor_id', $vendor->id)
            ->where('uuid', $productUuid)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        DB::beginTransaction();

        try {
            // Delete from Magento
            $magentoService = $this->initMagentoService($vendor);
            $magentoService->magento->delete("products/{$product->sku}");

            // Soft delete from local DB
            $product->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully from Magento and local database'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Product deletion failed', [
                'product_uuid' => $productUuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Product deletion failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/vendor/{vendor_uuid}/products/sync/{product_uuid}
     * Force sync single product to Magento
     */
    public function forceSync(string $vendorUuid, string $productUuid)
    {
        $vendor = $this->getVendor($vendorUuid);

        $product = VendorProduct::where('vendor_id', $vendor->id)
            ->where('uuid', $productUuid)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        try {
            $magentoService = $this->initMagentoService($vendor);

            // Get complete product data
            $productData = $product->complete_product_data;

            // Check if product exists in Magento
            $existingProduct = $magentoService->getProductBySku($product->sku);

            if ($existingProduct) {
                // Update existing product
                $updatePayload = $this->buildUpdatePayload($productData, $productData);
                $magentoService->updateProduct($product->sku, $updatePayload);
            } else {
                // Create new product
                $magentoService->createProduct($productData);
            }

            $product->update([
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'sync_errors' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product synced successfully'
            ]);
        } catch (\Exception $e) {
            $product->update([
                'sync_status' => 'failed',
                'sync_errors' => ['sync_error' => $e->getMessage()],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

      /**
     * POST /api/vendor/{vendor_uuid}/products/sync/all
     * Force sync all product to Magento
     */

    public function fetchAllProducts(Request $request, string $vendorUuid)
    {
        try {

            $vendor = $this->getVendor($vendorUuid);

            $service = ProductService::forVendor($vendor);

            $products = $service->fetchAllProducts($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Magento products fetched successfully',
                'data' => $products,
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    /**
     * Build complete Magento API payload
     */
    protected function buildMagentoPayload(array $data): array
    {
        // Process media gallery properly - CHECK BOTH KEYS
        $mediaGallery = [];

        if (isset($data['media_gallery']) && is_array($data['media_gallery'])) {
            foreach ($data['media_gallery'] as $mediaItem) {
                if (isset($mediaItem['content']['base64_encoded_data'])) {
                    $base64Data = $mediaItem['content']['base64_encoded_data'];
                    if (strpos($base64Data, 'base64,') !== false) {
                        $base64Data = substr($base64Data, strpos($base64Data, 'base64,') + 7);
                    }

                    // Add filename sanitization here too
                    $originalName = $mediaItem['content']['name'] ?? 'image.jpg';
                    $sanitizedName = $this->sanitizeFilename2($originalName); // You'll need to add this method to controller or use a helper

                    $mediaGallery[] = [
                        'media_type' => $mediaItem['media_type'] ?? 'image',
                        'label' => $mediaItem['label'] ?? '',
                        'position' => (int)($mediaItem['position'] ?? 0),
                        'disabled' => (bool)($mediaItem['disabled'] ?? false),
                        'types' => $mediaItem['types'] ?? [],
                        'content' => [
                            'base64_encoded_data' => $base64Data,
                            'type' => $mediaItem['content']['type'] ?? 'image/jpeg',
                            'name' => $sanitizedName  // Use sanitized name
                        ]
                    ];
                }
            }
        }

        return [
            // Core product data
            'sku' => $data['sku'],
            'name' => $data['name'],
            'type_id' => $data['type_id'],
            'attribute_set_id' => (int) $data['attribute_set_id'],
            'price' => (float) $data['price'],
            'status' => (int) ($data['status'] ?? 1),
            'visibility' => (int) ($data['visibility'] ?? 4),
            'weight' => (float) ($data['weight'] ?? 0),
            'tax_class_id' => (int) ($data['tax_class_id'] ?? 0),

            // Quantity and stock
            'qty' => (int) ($data['quantity'] ?? 0),
            'manage_stock' => (bool) ($data['manage_stock'] ?? true),
            'backorders' => (int) ($data['backorders'] ?? 0),
            'notify_stock_qty' => (int) ($data['notify_stock_qty'] ?? 0),
            'min_sale_qty' => (int) ($data['min_sale_qty'] ?? 1),
            'max_sale_qty' => (int) ($data['max_sale_qty'] ?? 0),
            'qty_increments' => (int) ($data['qty_increments'] ?? 1),
            'enable_qty_increments' => (bool) ($data['enable_qty_increments'] ?? false),

            // Website IDs
            'website_ids' => $data['website_ids'] ?? [1],

            // Content
            'description' => $data['description'] ?? '',
            'short_description' => $data['short_description'] ?? '',

            // SEO
            'url_key' => $data['url_key'] ?? $this->generateUrlKey($data['name']),
            'meta_title' => $data['meta_title'] ?? $data['name'],
            'meta_keyword' => $data['meta_keyword'] ?? '',
            'meta_description' => $data['meta_description'] ?? '',

            // Advanced Pricing
            'special_price' => $data['special_price'] ?? null,
            'special_from_date' => $data['special_from_date'] ?? null,
            'special_to_date' => $data['special_to_date'] ?? null,
            'cost' => $data['cost'] ?? null,
            'msrp' => $data['msrp'] ?? null,
            'msrp_display_actual_price_type' => $data['msrp_display_actual_price_type'] ?? null,

            // Design
            'custom_design' => $data['custom_design'] ?? null,
            'page_layout' => $data['page_layout'] ?? null,
            'custom_layout_update' => $data['custom_layout_update'] ?? null,

            // Gift Options
            'gift_message_available' => $data['gift_message_available'] ?? false,

            // Badge Dates
            'news_from_date' => $data['news_from_date'] ?? null,
            'news_to_date' => $data['news_to_date'] ?? null,
            'country_of_manufacture' => $data['country_of_manufacture'] ?? null,

            // Categories
            'category_ids' => $data['category_ids'] ?? [],

            // Media - Ensure this key exists even if empty
            'media' => $mediaGallery,

            // Product Links
            'product_links' => array_map(function ($link) {
                return [
                    'link_type' => $link['link_type'] ?? 'related',
                    'linked_sku' => $link['linked_sku'] ?? $link['linked_product_sku'] ?? '',  // Normalize
                    'linked_type' => $link['linked_type'] ?? $link['linked_product_type'] ?? 'simple',
                    'position' => $link['position'] ?? 0,
                ];
            }, $data['product_links'] ?? []),

            // Custom Options
            'custom_options' => $data['custom_options'] ?? [],

            // Tier Prices
            'tier_prices' => array_map(function ($tier) {
                return [
                    'price' => (float) ($tier['price'] ?? 0),
                    'price_type' => $tier['price_type'] ?? 'fixed',
                    'customer_group' => $tier['customer_group'] ?? 'ALL GROUPS',
                    'quantity' => (float) ($tier['quantity'] ?? 1),
                    'website_id' => (int) ($tier['website_id'] ?? 0),
                ];
            }, $data['tier_prices'] ?? []),

            // MSI Inventory
            'inventory' => $data['inventory'] ?? [
                'source_code' => 'default',
                'quantity' => $data['quantity'] ?? 0
            ],

            // Configurable Options
            'configurable_options' => $data['configurable_options'] ?? [],

            // Dynamic Attributes
            'dynamic_attributes' => $data['dynamic_attributes'] ?? [],
        ];
    }

    /**
     * Build update payload for Magento
     */
    protected function buildUpdatePayload(array $newData, array $existingData): array
    {
        $payload = [];

        // Fields that can be updated directly
        $directFields = ['price', 'status', 'visibility', 'weight', 'tax_class_id'];
        foreach ($directFields as $field) {
            if (isset($newData[$field])) {
                $payload[$field] = $newData[$field];
            }
        }

        // Handle custom attributes
        $customAttributes = [];
        $attributeFields = [
            'description',
            'short_description',
            'url_key',
            'meta_title',
            'meta_keyword',
            'meta_description',
            'special_price',
            'special_from_date',
            'special_to_date',
            'cost',
            'msrp',
            'msrp_display_actual_price_type',
            'custom_design',
            'page_layout',
            'custom_layout_update',
            'gift_message_available',
            'news_from_date',
            'news_to_date',
            'country_of_manufacture'
        ];

        foreach ($attributeFields as $field) {
            if (isset($newData[$field])) {
                $value = $newData[$field];
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                if (str_contains($field, '_date') && $value && strlen($value) === 10) {
                    $value .= ' 00:00:00';
                }
                $customAttributes[] = ['attribute_code' => $field, 'value' => (string) $value];
            }
        }

        // Dynamic attributes
        if (!empty($newData['dynamic_attributes'])) {
            foreach ($newData['dynamic_attributes'] as $code => $value) {
                if ($value !== null && $value !== '') {
                    $customAttributes[] = [
                        'attribute_code' => $code,
                        'value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                    ];
                }
            }
        }

        if (!empty($customAttributes)) {
            $payload['custom_attributes'] = $customAttributes;
        }

        return $payload;
    }

    /**
     * Handle additional updates (categories, media, links, etc.)
     */
    protected function handleAdditionalUpdates(ProductService $service, string $sku, array $data): void
    {
        // Update categories
        if (isset($data['category_ids'])) {
            $service->assignCategories($sku, $data['category_ids']);
        }

        // Update media
        if (isset($data['media']) && !empty($data['media'])) {
            foreach ($data['media'] as $media) {
                $service->uploadMedia($sku, $media);
            }
        }

        // Update product links
        if (isset($data['product_links']) && !empty($data['product_links'])) {
            $service->assignProductLinks($sku, $data['product_links']);
        }

        // Update custom options
        if (isset($data['custom_options']) && !empty($data['custom_options'])) {
            $service->addCustomOptions($sku, $data['custom_options']);
        }

        // Update tier prices
        if (isset($data['tier_prices']) && !empty($data['tier_prices'])) {
            $service->setTierPrices($sku, $data['tier_prices']);
        }

        // Update inventory
        if (isset($data['inventory'])) {
            $service->assignMSIInventory($sku, $data['inventory']);
        }

        // Update configurable options
        if (isset($data['configurable_options']) && !empty($data['configurable_options'])) {
            $service->addConfigurableOptions($sku, $data['configurable_options']);
        }
    }

    /**
     * Prepare fields for local database update
     */
    protected function prepareLocalUpdateFields(array $data): array
    {
        $fields = [];

        $mappable = [
            'name',
            'type_id',
            'attribute_set_id',
            'price',
            'status'
        ];

        foreach ($mappable as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
            }
        }

        if (isset($data['quantity'])) {
            $fields['quantity'] = $data['quantity'];
        }

        return $fields;
    }
    protected function sanitizeFilename2(string $filename): string
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
     * Generate URL key from name
     */
    protected function generateUrlKey(string $name): string
    {
        $key = strtolower($name);
        $key = preg_replace('/[^a-z0-9-]/', '-', $key);
        $key = preg_replace('/-+/', '-', $key);
        return trim($key, '-');
    }
}
