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

    public function __construct(VendorService $vendorService)
    {
        $this->vendorService = $vendorService;
    }

    protected function getVendor(string $vendorUuid)
    {
        $vendor = $this->vendorService->getVendorByUuid($vendorUuid);
        if (!$vendor) {
            abort(404, 'Vendor not found');
        }
        return $vendor;
    }

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
     * POST /api/vendor/{vendor_uuid}/products/sync/all
     * Force sync all product to Magento
     */

    public function fetchAllProducts(Request $request, string $vendorUuid)
    {
        try {
            $vendor = $this->getVendor($vendorUuid);
            $service = ProductService::forVendor($vendor);

            // Fetch all products from Magento
            $magentoResponse = $service->fetchAllProducts($request->all());

            if (!isset($magentoResponse['items'])) {
                throw new \Exception('Invalid response structure from Magento API');
            }

            $magentoProducts = $magentoResponse['items'];
            $totalInMagento  = $magentoResponse['total_count'] ?? count($magentoProducts);

            if (empty($magentoProducts)) {
                return response()->json([
                    'success' => true,
                    'message' => 'No products found in Magento to sync',
                    'data'    => [
                        'total_in_magento' => 0,
                        'inserted'         => 0,
                        'skipped'          => 0,
                        'failed'           => 0,
                        'errors'           => [],
                    ],
                ]);
            }

            // ── Step 1: Extract all Magento product IDs from the response ──────────
            $magentoProductIds = array_filter(array_column($magentoProducts, 'id'));

            // ── Step 2: Find which IDs already exist in local DB ──────────────────
            $existingIds = VendorProduct::where('vendor_id', $vendor->id)
                ->whereIn('magento_product_id', $magentoProductIds)
                ->pluck('magento_product_id')
                ->flip()          // flip to use isset() instead of in_array() — O(1)
                ->toArray();

            // ── Step 3: Get vendor store ID once (not inside the loop) ────────────
            $vendorStoreId = null;

            if ($request->has('store_uuid')) {
                $store = \App\Models\Vendor\VendorStore::where('uuid', $request->store_uuid)
                    ->where('vendor_id', $vendor->id)
                    ->first();
                $vendorStoreId = $store?->id;
            }

            if (!$vendorStoreId) {
                $vendorStoreId = \App\Models\Vendor\VendorStore::where('vendor_id', $vendor->id)
                    ->value('id');
            }

            // ── Step 4: Insert only new products ─────────────────────────────────
            $insertedCount = 0;
            $skippedCount  = 0;
            $failedCount   = 0;
            $errors        = [];

            DB::beginTransaction();

            try {
                foreach ($magentoProducts as $magentoProduct) {
                    $magentoId = $magentoProduct['id'] ?? null;

                    // Skip products with no ID
                    if (!$magentoId) {
                        $skippedCount++;
                        $errors[] = ['sku' => $magentoProduct['sku'] ?? 'unknown', 'error' => 'Missing Magento product ID'];
                        continue;
                    }

                    // Skip if already exists locally
                    if (isset($existingIds[$magentoId])) {
                        $skippedCount++;
                        continue;
                    }

                    // Skip if no SKU
                    if (empty($magentoProduct['sku'])) {
                        $skippedCount++;
                        $errors[] = ['sku' => 'unknown', 'error' => "Product ID {$magentoId} has no SKU"];
                        continue;
                    }

                    try {
                        $quantity = (int) (
                            $magentoProduct['extension_attributes']['stock_item']['qty']
                            ?? $magentoProduct['quantity']
                            ?? 0
                        );

                        VendorProduct::create([
                            'uuid'               => (string) \Illuminate\Support\Str::uuid(),
                            'vendor_id'          => $vendor->id,
                            'vendor_store_id'    => $vendorStoreId,
                            'magento_product_id' => $magentoId,
                            'magento_sku'        => $magentoProduct['sku'],
                            'sku'                => $magentoProduct['sku'],
                            'name'               => $magentoProduct['name'] ?? '',
                            'type_id'            => $magentoProduct['type_id'] ?? 'simple',
                            'attribute_set_id'   => $magentoProduct['attribute_set_id'] ?? 4,
                            'price'              => (float) ($magentoProduct['price'] ?? 0),
                            'quantity'           => $quantity,
                            'status'             => ((int) ($magentoProduct['status'] ?? 1)) === 1,
                            'full_product_data'  => $magentoProduct,
                            'sync_status'        => 'synced',
                            'last_synced_at'     => now(),
                            'metadata'           => json_encode([
                                'synced_from' => 'magento',
                                'sync_date'   => now()->toISOString(),
                            ]),
                        ]);

                        $insertedCount++;
                    } catch (\Exception $e) {
                        $failedCount++;
                        $errors[] = ['sku' => $magentoProduct['sku'], 'error' => $e->getMessage()];
                        Log::error('Failed to insert product during sync', [
                            'sku'        => $magentoProduct['sku'],
                            'magento_id' => $magentoId,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'success' => true,
                'message' => "Sync complete: {$insertedCount} inserted, {$skippedCount} skipped (already exist), {$failedCount} failed. Total in Magento: {$totalInMagento}.",
                'data'    => [
                    'total_in_magento' => $totalInMagento,
                    'inserted'         => $insertedCount,
                    'skipped'          => $skippedCount,
                    'failed'           => $failedCount,
                    'errors'           => $errors,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('fetchAllProducts sync failed', [
                'vendor_uuid' => $vendorUuid,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to sync products: ' . $e->getMessage(),
            ], 500);
        }
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
     * Store product - Routes to appropriate handler based on type
     */
    public function store(CreateProductRequest $request, string $vendorUuid)
    {
        $vendor = $this->getVendor($vendorUuid);

        // Generate SKU if not provided or sanitize
        $generatedSku = Str::upper(Str::slug($request->sku, '-'));
        $request->merge(['sku' => $generatedSku]);

        // Get vendor store ID
        $vendorStoreId = $this->getVendorStoreId($vendor, $request);

        if (!$vendorStoreId) {
            return response()->json([
                'success' => false,
                'message' => 'No store found for this vendor. Please create a store first.'
            ], 400);
        }

        $magentoService = $this->initMagentoService($vendor);
        $validatedData = $request->validated();
        $productType = $validatedData['type_id'];

        DB::beginTransaction();

        try {
            // Route to appropriate product type handler
            $magentoResponse = match ($productType) {
                'configurable' => $this->createConfigurableProduct($magentoService, $validatedData),
                'grouped' => $this->createGroupedProduct($magentoService, $validatedData),
                'bundle' => $this->createBundleProduct($magentoService, $validatedData),
                'downloadable' => $this->createDownloadableProduct($magentoService, $validatedData),
                'virtual' => $this->createVirtualProduct($magentoService, $validatedData),
                'giftcard' => $this->createGiftCardProduct($magentoService, $validatedData),
                default => $this->createSimpleProduct($magentoService, $validatedData),
            };

            if (!$magentoResponse['success']) {
                throw new \Exception($magentoResponse['message'] ?? 'Magento product creation failed');
            }

            // Save to local database
            $product = VendorProduct::create([
                'vendor_id' => $vendor->id,
                'vendor_store_id' => $vendorStoreId,
                'magento_product_id' => $magentoResponse['product']['id'] ?? null,
                'magento_sku' => $magentoResponse['sku'],
                'sku' => $generatedSku,
                'name' => $validatedData['name'],
                'type_id' => $productType,
                'attribute_set_id' => $validatedData['attribute_set_id'] ?? 4,
                'price' => $validatedData['price'] ?? 0,
                'quantity' => $validatedData['quantity'] ?? 0,
                'status' => $validatedData['status'] ?? true,
                'full_product_data' => $validatedData,
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
                'type' => $productType,
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
     * Create Simple Product
     */
    protected function createSimpleProduct(ProductService $service, array $data): array
    {
        $payload = $this->buildMagentoPayload($data);
        return $service->createProduct($payload);
    }

    protected function handleAdditionalUpdates(ProductService $service, string $sku, array $data): void
    {
        if (isset($data['category_ids'])) {
            $service->assignCategories($sku, $data['category_ids']);
        }

        if (isset($data['media']) && !empty($data['media'])) {
            foreach ($data['media'] as $media) {
                $service->uploadMedia($sku, $media);
            }
        }

        if (isset($data['product_links']) && !empty($data['product_links'])) {
            $service->assignProductLinks($sku, $data['product_links']);
        }

        if (isset($data['custom_options']) && !empty($data['custom_options'])) {
            $service->addCustomOptions($sku, $data['custom_options']);
        }

        if (isset($data['tier_prices']) && !empty($data['tier_prices'])) {
            $service->setTierPrices($sku, $data['tier_prices']);
        }

        if (isset($data['inventory'])) {
            $service->assignMSIInventory($sku, $data['inventory']);
        }
    }

    protected function prepareLocalUpdateFields(array $data): array
    {
        $fields = [];

        $mappable = ['name', 'type_id', 'attribute_set_id', 'price', 'status'];
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

    protected function buildUpdatePayload(array $newData, array $existingData): array
    {
        $payload = [];

        $directFields = ['price', 'status', 'visibility', 'weight', 'tax_class_id'];
        foreach ($directFields as $field) {
            if (isset($newData[$field])) {
                $payload[$field] = $newData[$field];
            }
        }

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
            'msrp'
        ];

        foreach ($attributeFields as $field) {
            if (isset($newData[$field])) {
                $value = $newData[$field];
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                $customAttributes[] = ['attribute_code' => $field, 'value' => (string) $value];
            }
        }

        if (!empty($customAttributes)) {
            $payload['custom_attributes'] = $customAttributes;
        }

        return $payload;
    }

    /**
     * Create Configurable Product
     */
    protected function createConfigurableProduct(ProductService $service, array $data): array
    {
        if (empty($data['configurable_variants'])) {
            throw new \Exception('Configurable products require at least one variant. Please generate variants first.');
        }

        foreach ($data['configurable_variants'] as &$variant) {
            if (empty($variant['attribute_set_id'])) {
                $variant['attribute_set_id'] = $data['attribute_set_id'] ?? 4;
            }
        }
        unset($variant); // break reference

        $parentPayload = $this->buildMagentoPayload($data);

        // ✅ These three were never being carried through buildMagentoPayload
        $parentPayload['configurable_variants'] = $data['configurable_variants'];
        $parentPayload['configurable_options']  = $data['configurable_options'] ?? [];
        $parentPayload['inventory']             = $data['inventory'] ?? [];

        \Log::info('Configurable payload to service', [
            'sku'             => $parentPayload['sku'],
            'options_count'   => count($parentPayload['configurable_options']),
            'variants_count'  => count($parentPayload['configurable_variants']),
        ]);

        return $service->createConfigurableProduct($parentPayload);
    }

    protected function convertOptionsToAttributes(array $options): array
    {
        $attributes = [];
        foreach ($options as $option) {
            $attributes[] = [
                'attribute_id' => $option['attribute_id'],
                'label' => $option['label'],
                'values' => $option['values']
            ];
        }
        return $attributes;
    }
    /**
     * Create Grouped Product
     */
    protected function createGroupedProduct(ProductService $service, array $data): array
    {
        if (empty($data['grouped_links'])) {
            throw new \Exception('Grouped products require at least one associated product');
        }

        $payload = $this->buildMagentoPayload($data);

        // Add grouped links to payload
        $payload['grouped_links'] = $data['grouped_links'];

        return $service->createGroupedProduct($payload);
    }

    /**
     * Create Bundle Product
     */
    protected function createBundleProduct(ProductService $service, array $data): array
    {
        if (empty($data['bundle_options'])) {
            throw new \Exception('Bundle products require at least one bundle option');
        }

        $payload = $this->buildMagentoPayload($data);

        // Add bundle-specific fields
        $payload['bundle_options'] = $data['bundle_options'];
        $payload['bundle_shipping_type'] = $data['bundle_shipping_type'] ?? 'together';
        $payload['bundle_price_type'] = $data['bundle_price_type'] ?? 'dynamic';
        $payload['bundle_sku_type'] = $data['bundle_sku_type'] ?? 'dynamic';

        return $service->createBundleProduct($payload);
    }

    /**
     * Create Downloadable Product
     */
    protected function createDownloadableProduct(ProductService $service, array $data): array
    {
        $payload = $this->buildMagentoPayload($data);

        // Add downloadable-specific fields
        if (!empty($data['downloadable_links'])) {
            $payload['downloadable_links'] = $data['downloadable_links'];
        }
        if (!empty($data['downloadable_samples'])) {
            $payload['downloadable_samples'] = $data['downloadable_samples'];
        }
        $payload['links_purchased_separately'] = $data['links_purchased_separately'] ?? false;
        $payload['links_title'] = $data['links_title'] ?? 'Downloads';
        $payload['samples_title'] = $data['samples_title'] ?? 'Samples';

        return $service->createDownloadableProduct($payload);
    }

    /**
     * Create Virtual Product
     */
    protected function createVirtualProduct(ProductService $service, array $data): array
    {
        $payload = $this->buildMagentoPayload($data);
        $payload['type_id'] = 'virtual';
        // Remove weight for virtual products
        unset($payload['weight']);

        return $service->createProduct($payload);
    }

    /**
     * Create Gift Card Product
     */
    protected function createGiftCardProduct(ProductService $service, array $data): array
    {
        $payload = $this->buildMagentoPayload($data);

        // Add gift card-specific fields
        $payload['giftcard_type'] = $data['giftcard_type'] ?? 'virtual';
        $payload['giftcard_amount_type'] = $data['giftcard_amount_type'] ?? 'fixed';

        if (!empty($data['giftcard_amounts'])) {
            $payload['giftcard_amounts'] = $data['giftcard_amounts'];
        }

        if ($data['giftcard_amount_type'] === 'dynamic') {
            $payload['giftcard_open_amount_min'] = $data['giftcard_open_amount_min'] ?? 0;
            $payload['giftcard_open_amount_max'] = $data['giftcard_open_amount_max'] ?? 0;
        }

        $payload['allow_message'] = $data['allow_message'] ?? true;
        $payload['gift_message_max_length'] = $data['gift_message_max_length'] ?? 255;

        return $service->createGiftCardProduct($payload);
    }

    /**
     * Get vendor store ID
     */
    protected function getVendorStoreId($vendor, $request = null): ?int
    {
        $vendorStoreId = null;

        if ($request && $request->has('vendor_store_id')) {
            $store = \App\Models\Vendor\VendorStore::where('uuid', $request->vendor_store_id)
                ->where('vendor_id', $vendor->id)
                ->first();
            if ($store) {
                $vendorStoreId = $store->id;
            }
        }

        if (!$vendorStoreId) {
            $store = \App\Models\Vendor\VendorStore::where('vendor_id', $vendor->id)->first();
            if ($store) {
                $vendorStoreId = $store->id;
            }
        }

        return $vendorStoreId;
    }

    /**
     * Build Magento API payload
     */
    protected function buildMagentoPayload(array $data): array
    {
        $mediaGallery = [];

        if (isset($data['media_gallery']) && is_array($data['media_gallery'])) {
            foreach ($data['media_gallery'] as $mediaItem) {
                if (isset($mediaItem['content']['base64_encoded_data'])) {
                    $base64Data = $mediaItem['content']['base64_encoded_data'];
                    if (strpos($base64Data, 'base64,') !== false) {
                        $base64Data = substr($base64Data, strpos($base64Data, 'base64,') + 7);
                    }

                    $originalName = $mediaItem['content']['name'] ?? 'image.jpg';
                    $sanitizedName = $this->sanitizeFilename($originalName);

                    $mediaGallery[] = [
                        'media_type' => $mediaItem['media_type'] ?? 'image',
                        'label' => $mediaItem['label'] ?? '',
                        'position' => (int)($mediaItem['position'] ?? 0),
                        'disabled' => (bool)($mediaItem['disabled'] ?? false),
                        'types' => $mediaItem['types'] ?? [],
                        'content' => [
                            'base64_encoded_data' => $base64Data,
                            'type' => $mediaItem['content']['type'] ?? 'image/jpeg',
                            'name' => $sanitizedName
                        ]
                    ];
                }
            }
        }

        $payload = [
            'sku' => $data['sku'],
            'name' => $data['name'],
            'type_id' => $data['type_id'],
            'attribute_set_id' => (int)($data['attribute_set_id'] ?? 4),
            'price' => (float)($data['price'] ?? 0),
            'status' => (int)($data['status'] ?? 1),
            'visibility' => (int)($data['visibility'] ?? 4),
            'tax_class_id' => (int)($data['tax_class_id'] ?? 0),
            'qty' => (int)($data['quantity'] ?? 0),
            'manage_stock' => (bool)($data['manage_stock'] ?? true),
            'backorders' => (int)($data['backorders'] ?? 0),
            'notify_stock_qty' => (int)($data['notify_stock_qty'] ?? 0),
            'min_sale_qty' => (int)($data['min_sale_qty'] ?? 1),
            'max_sale_qty' => (int)($data['max_sale_qty'] ?? 0),
            'qty_increments' => (int)($data['qty_increments'] ?? 1),
            'enable_qty_increments' => (bool)($data['enable_qty_increments'] ?? false),
            'website_ids' => $data['website_ids'] ?? [1],
            'description' => $data['description'] ?? '',
            'short_description' => $data['short_description'] ?? '',
            'url_key' => $data['url_key'] ?? $this->generateUrlKey($data['name']),
            'meta_title' => $data['meta_title'] ?? $data['name'],
            'meta_keyword' => $data['meta_keyword'] ?? '',
            'meta_description' => $data['meta_description'] ?? '',
            'special_price' => $data['special_price'] ?? null,
            'special_from_date' => $data['special_from_date'] ?? null,
            'special_to_date' => $data['special_to_date'] ?? null,
            'cost' => $data['cost'] ?? null,
            'msrp' => $data['msrp'] ?? null,
            'msrp_display_actual_price_type' => $data['msrp_display_actual_price_type'] ?? null,
            'custom_design' => $data['custom_design'] ?? null,
            'page_layout' => $data['page_layout'] ?? null,
            'custom_layout_update' => $data['custom_layout_update'] ?? null,
            'gift_message_available' => $data['gift_message_available'] ?? false,
            'news_from_date' => $data['news_from_date'] ?? null,
            'news_to_date' => $data['news_to_date'] ?? null,
            'country_of_manufacture' => $data['country_of_manufacture'] ?? null,
            'category_ids' => $data['category_ids'] ?? [],
            'media' => $mediaGallery,
            'product_links' => array_map(function ($link) {
                return [
                    'link_type' => $link['link_type'] ?? 'related',
                    'linked_sku' => $link['linked_sku'] ?? '',
                    'linked_type' => $link['linked_type'] ?? 'simple',
                    'position' => $link['position'] ?? 0,
                ];
            }, $data['product_links'] ?? []),
            'custom_options' => $data['custom_options'] ?? [],
            'tier_prices' => array_map(function ($tier) {
                return [
                    'price' => (float)($tier['price'] ?? 0),
                    'price_type' => $tier['price_type'] ?? 'fixed',
                    'customer_group' => $tier['customer_group'] ?? 'ALL GROUPS',
                    'quantity' => (float)($tier['quantity'] ?? 1),
                    'website_id' => (int)($tier['website_id'] ?? 0),
                ];
            }, $data['tier_prices'] ?? []),
            'inventory' => $data['inventory'] ?? [
                'source_code' => 'default',
                'quantity' => $data['quantity'] ?? 0
            ],
            'dynamic_attributes' => $data['dynamic_attributes'] ?? [],
        ];

        // Remove weight for virtual/downloadable/giftcard
        if (in_array($data['type_id'], ['virtual', 'downloadable', 'giftcard'])) {
            unset($payload['weight']);
        } else {
            $payload['weight'] = (float)($data['weight'] ?? 0);
        }

        return $payload;
    }

    protected function sanitizeFilename(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $basename = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $basename);
        $basename = preg_replace('/-+/', '-', $basename);
        $basename = trim($basename, '-');

        if (empty($basename)) {
            $basename = 'image';
        }

        $basename = $basename . '_' . time() . '_' . rand(100, 999);

        if (!empty($extension)) {
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $extension = strtolower($extension);
            if (!in_array($extension, $allowedExtensions)) {
                $extension = 'jpg';
            }
            return $basename . '.' . $extension;
        }

        return $basename . '.jpg';
    }

    protected function generateUrlKey(string $name): string
    {
        $key = strtolower($name);
        $key = preg_replace('/[^a-z0-9-]/', '-', $key);
        $key = preg_replace('/-+/', '-', $key);
        return trim($key, '-');
    }

    /**
     * GET /api/by-vendor/{vendor_uuid}/products/configurable-attributes/all
     * Get all configurable attributes from Magento
     */
    public function getConfigurableAttributes(string $vendorUuid, Request $request)
    {
        $vendor = $this->getVendor($vendorUuid);
        $productService = $this->initMagentoService($vendor);

        try {
            // Pass the entire request query to the service
            $attributes = $productService->getConfigurableAttributes($request->query());

            return response()->json([
                'success' => true,
                'data' => $attributes,
                'message' => 'Configurable attributes retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch configurable attributes', [
                'vendor_uuid' => $vendorUuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch configurable attributes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/by-vendor/{vendor_uuid}/products/configurable-attributes/{attributeId}/options
     * Get options for a specific attribute
     */
    public function getAttributeOptions(string $vendorUuid, int $attributeId)
    {
        $vendor = $this->getVendor($vendorUuid);
        $productService = $this->initMagentoService($vendor);

        try {
            $options = $productService->getAttributeOptions($attributeId);

            return response()->json([
                'success' => true,
                'data' => $options,
                'message' => 'Attribute options retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch attribute options', [
                'vendor_uuid' => $vendorUuid,
                'attribute_id' => $attributeId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attribute options: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/by-vendor/{vendor_uuid}/products/attributes
     * Get all product attributes
     */
    public function getAllAttributes(string $vendorUuid)
    {
        $vendor = $this->getVendor($vendorUuid);
        $productService = $this->initMagentoService($vendor);

        try {
            $attributes = $productService->getProductAttributes();

            return response()->json([
                'success' => true,
                'data' => $attributes,
                'message' => 'Attributes retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attributes: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/by-vendor/{vendor_uuid}/products/attributes/{attributeId}
     * Get specific attribute details
     */
    public function getAttribute(string $vendorUuid, int $attributeId)
    {
        $vendor = $this->getVendor($vendorUuid);
        $productService = $this->initMagentoService($vendor);

        try {
            $attribute = $productService->getAttribute($attributeId);

            return response()->json([
                'success' => true,
                'data' => $attribute,
                'message' => 'Attribute retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch attribute: ' . $e->getMessage()
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
            $product->update(['sync_status' => 'updating']);

            $magentoService = $this->initMagentoService($vendor);
            $updateData = $this->buildUpdatePayload($request->validated(), $product->full_product_data ?? []);

            if (!empty($updateData)) {
                $magentoService->updateProduct($product->sku, $updateData);
            }

            $this->handleAdditionalUpdates($magentoService, $product->sku, $request->validated());

            $updateFields = $this->prepareLocalUpdateFields($request->validated());
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

            $product->update([
                'sync_status' => 'failed',
                'sync_errors' => json_encode(['update_error' => $e->getMessage()])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Product update failed: ' . $e->getMessage()
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
            $productData = $product->complete_product_data;
            $existingProduct = $magentoService->getProductBySku($product->sku);

            if ($existingProduct) {
                $updatePayload = $this->buildUpdatePayload($productData, $productData);
                $magentoService->updateProduct($product->sku, $updatePayload);
            } else {
                $magentoPayload = $this->buildMagentoPayload($productData);
                $magentoService->createProduct($magentoPayload);
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
                'sync_errors' => json_encode(['sync_error' => $e->getMessage()]),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
