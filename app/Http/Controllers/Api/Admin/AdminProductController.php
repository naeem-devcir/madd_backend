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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Throwable;

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

            $validated = $request->validate([
                'vendor_id'          => 'required|integer|exists:vendors,id',
                'vendor_store_id'    => 'required|integer|exists:vendor_stores,id',
                'sku'                => [
                    'required', 'string', 'max:255',
                    Rule::unique('vendor_products', 'sku')
                        ->where(fn ($q) => $q->where('vendor_id', $request->vendor_id)),
                ],
                'name'               => 'required|string|max:500',
                'description'        => 'nullable|string',
                'short_description'  => 'nullable|string',
                'price'              => 'required|numeric|min:0',
                'status'             => 'nullable|in:active,inactive',
                'special_price'      => 'nullable|numeric|min:0',
                'special_price_from' => 'nullable|date',
                'special_price_to'   => 'nullable|date|after_or_equal:special_price_from',
                'quantity'           => 'nullable|integer|min:0',
                'weight'             => 'nullable|numeric|min:0',
                'categories'         => 'nullable|array',
                'attributes'         => 'nullable|array',
                'media_gallery'      => 'nullable|array',
                'seo_data'           => 'nullable|array',
            ]);

            $product = $this->productService->createAdminProduct($validated, auth()->id());

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

            $product = $this->productService->updateAdminProduct($product, $validated, auth()->id());

            return response()->json([
                'success' => true,
                'message' => 'Product updated in Magento and Laravel successfully',
                'data'    => new ProductResource($product->load(['vendor', 'store', 'draft'])),
            ]);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ModelNotFoundException) {
            return $this->notFound($vendorId ? 'Product not found for this vendor' : 'Product not found');
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to update product', $e);
        }
    }

    // -------------------------------------------------------------------------
    // DELETE
    // -------------------------------------------------------------------------

    /**
     * DELETE /admin/products/{uuid}
     * DELETE /admin/vendors/{vendorId}/products/{uuid}
     *
     * Write flow: Magento delete first → local soft-delete with audit trail
     * Blocked if product has existing orders (returns 409)
     */
    public function destroy(string $uuid, ?int $vendorId = null): JsonResponse
    {
        try {
            $query = VendorProduct::where('uuid', $uuid);

            if ($vendorId) {
                $query->where('vendor_id', $vendorId);
            }

            $product = $query->firstOrFail();

            $result = $this->productService->deleteAdminProduct($product, auth()->id());

            if ($result['blocked']) {
                return response()->json([
                    'success'     => false,
                    'message'     => $result['reason'],
                    'order_count' => $result['order_count'],
                ], 409);
            }

            return response()->json([
                'success' => true,
                'message' => 'Product deleted from Magento and Laravel successfully',
                'data'    => [
                    'id'                 => $product->id,
                    'uuid'               => $product->uuid,
                    'name'               => $product->name,
                    'magento_product_id' => $product->magento_product_id,
                    'magento_sku'        => $product->magento_sku,
                ],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($vendorId ? 'Product not found for this vendor' : 'Product not found');
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to delete product', $e);
        }
    }

    // -------------------------------------------------------------------------
    // DRAFT APPROVAL WORKFLOW
    // -------------------------------------------------------------------------

    /**
     * GET /admin/products/drafts/pending
     *
     * Query params:
     *   - vendor_id  (int)  optional filter
     *   - per_page   (int)  default 20
     */
    public function pending(Request $request): JsonResponse
    {
        try {
            $query = ProductDraft::with(['vendor', 'store', 'product'])
                ->where('status', 'pending')
                ->orderBy('created_at', 'asc');

            if ($request->filled('vendor_id') && is_numeric($request->vendor_id)) {
                $query->where('vendor_id', $request->vendor_id);
            }

            $perPage  = min((int) $request->input('per_page', 20), 100);
            $drafts   = $query->paginate($perPage);
            $totalPending = ProductDraft::where('status', 'pending')->count();

            return response()->json([
                'success' => true,
                'data'    => $drafts,
                'meta'    => [
                    'total_pending' => $totalPending,
                    'current_page'  => $drafts->currentPage(),
                    'last_page'     => $drafts->lastPage(),
                    'per_page'      => $drafts->perPage(),
                    'total'         => $drafts->total(),
                ],
            ]);

        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to fetch pending products', $e);
        }
    }

    /**
     * POST /admin/products/drafts/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notes' => 'nullable|string|max:1000',
            ]);

            $draft = ProductDraft::with(['vendor', 'product'])->findOrFail($id);

            if ($draft->status !== 'pending') {
                return response()->json([
                    'success'        => false,
                    'message'        => 'Product is not pending approval',
                    'current_status' => $draft->status,
                ], 422);
            }

            // Transaction lives inside productService->approveProduct
            $product = $this->productService->approveProduct($draft, auth()->id(), $validated['notes'] ?? null);

            return response()->json([
                'success' => true,
                'message' => 'Product approved and synced with Magento successfully',
                'data'    => [
                    'product_id'         => $product->uuid,
                    'laravel_product_id' => $product->id,
                    'magento_product_id' => $product->magento_product_id,
                    'magento_sku'        => $product->magento_sku,
                    'draft_id'           => $draft->id,
                    'status'             => 'approved',
                    'approved_by'        => auth()->id(),
                    'approved_at'        => now(),
                ],
            ]);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ModelNotFoundException) {
            return $this->notFound('Product draft not found');
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to approve product', $e);
        }
    }

    /**
     * Create Bundle Product
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => 'required|string|min:5|max:1000',
            ]);

            $draft = ProductDraft::findOrFail($id);

            if ($draft->status !== 'pending') {
                return response()->json([
                    'success'        => false,
                    'message'        => 'Product is not pending approval',
                    'current_status' => $draft->status,
                ], 422);
            }

            // Transaction lives inside productService->rejectProduct
            $this->productService->rejectProduct($draft, auth()->id(), $validated['reason']);

            return response()->json([
                'success' => true,
                'message' => 'Product rejected successfully',
                'data'    => [
                    'draft_id'    => $draft->id,
                    'status'      => 'rejected',
                    'reason'      => $validated['reason'],
                    'rejected_by' => auth()->id(),
                    'rejected_at' => now(),
                ],
            ]);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ModelNotFoundException) {
            return $this->notFound('Product draft not found');
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to reject product', $e);
        }
    }

    /**
     * POST /admin/products/drafts/{id}/request-modification
     */
    public function requestModification(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notes' => 'required|string|min:10|max:1000',
            ]);

            $draft = ProductDraft::findOrFail($id);

            if ($draft->status !== 'pending') {
                return response()->json([
                    'success'        => false,
                    'message'        => 'Only pending drafts can be sent back for modification',
                    'current_status' => $draft->status,
                ], 422);
            }

            $this->productService->requestModification($draft, auth()->id(), $validated['notes']);

            return response()->json([
                'success' => true,
                'message' => 'Modification requested successfully',
                'data'    => [
                    'draft_id'      => $draft->id,
                    'status'        => 'needs_modification',
                    'notes'         => $validated['notes'],
                    'requested_by'  => auth()->id(),
                    'requested_at'  => now(),
                ],
            ]);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ModelNotFoundException) {
            return $this->notFound('Product draft not found');
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to request modification', $e);
        }
    }

    /**
     * Build Magento API payload
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'draft_ids'   => 'required|array|min:1|max:50',
                'draft_ids.*' => 'required|integer|exists:product_drafts,id',
                'notes'       => 'nullable|string|max:1000',
            ]);

            $results = ['approved' => [], 'failed' => []];

            // Each approval has its own internal transaction (inside productService)
            // We do NOT wrap all approvals in a single outer transaction so that
            // one Magento failure doesn't roll back already-successful approvals.
            foreach ($validated['draft_ids'] as $draftId) {
                try {
                    $draft = ProductDraft::find($draftId);

                    if (! $draft) {
                        $results['failed'][] = ['id' => $draftId, 'reason' => 'Draft not found'];
                        continue;
                    }

                    if ($draft->status !== 'pending') {
                        $results['failed'][] = ['id' => $draftId, 'reason' => "Status is '{$draft->status}', expected 'pending'"];
                        continue;
                    }

                    $this->productService->approveProduct($draft, auth()->id(), $validated['notes'] ?? null);
                    $results['approved'][] = $draftId;

                } catch (Throwable $e) {
                    $results['failed'][] = ['id' => $draftId, 'reason' => $e->getMessage()];
                }
            }

            $approvedCount = count($results['approved']);
            $totalCount    = count($validated['draft_ids']);

            return response()->json([
                'success' => true,
                'message' => "Approved {$approvedCount} of {$totalCount} products",
                'data'    => $results,
            ]);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to bulk approve products', $e);
        }
    }

    // -------------------------------------------------------------------------
    // STATISTICS
    // -------------------------------------------------------------------------

    /**
     * GET /admin/products/statistics
     * GET /admin/vendors/{vendorId}/products/statistics
     */
    public function statistics(Request $request, ?int $vendorId = null): JsonResponse
    {
        try {
            $base = VendorProduct::query();

            if ($vendorId) {
                $base->where('vendor_id', $vendorId);
            }

            // Clone base for each sub-query to keep vendor scope consistent
            $stats = [
                'total'              => (clone $base)->count(),
                'active'             => (clone $base)->where('status', 'active')->count(),
                'inactive'           => (clone $base)->where('status', 'inactive')->count(),
                'draft'              => (clone $base)->where('status', 'draft')->count(),
                'pending_sync'       => (clone $base)->where('sync_status', 'pending')->count(),
                'synced'             => (clone $base)->where('sync_status', 'synced')->count(),
                'failed_sync'        => (clone $base)->where('sync_status', 'failed')->count(),
                'pending_approval'   => ProductDraft::when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                                            ->where('status', 'pending')->count(),
                'total_value'        => (clone $base)->where('status', 'active')
                                            ->sum(DB::raw('price * quantity')),
                'average_price'      => (clone $base)->where('status', 'active')->avg('price'),

                // Top vendors by product count (skipped when scoped to a single vendor)
                'by_vendor'          => $vendorId ? null : VendorProduct::select('vendor_id', DB::raw('count(*) as count'))
                                            ->with('vendor:id,company_name,company_slug')
                                            ->whereHas('vendor')
                                            ->groupBy('vendor_id')
                                            ->orderByDesc('count')
                                            ->limit(10)
                                            ->get(),

                // Top 10 best-selling products (vendor-scoped when applicable)
                'top_products'       => DB::table('order_items')
                                            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
                                            ->when($vendorId, fn ($q) => $q->where('vendor_products.vendor_id', $vendorId))
                                            ->select(
                                                'vendor_products.id',
                                                'vendor_products.uuid',
                                                'vendor_products.name',
                                                DB::raw('SUM(order_items.qty_ordered) as total_sold'),
                                                DB::raw('SUM(order_items.qty_ordered * order_items.price) as total_revenue')
                                            )
                                            ->groupBy('vendor_products.id', 'vendor_products.uuid', 'vendor_products.name')
                                            ->orderByDesc('total_sold')
                                            ->limit(10)
                                            ->get(),

                // 5 most recently created products
                'recent_products'    => (clone $base)->with('vendor:id,company_name,company_slug')
                                            ->orderByDesc('created_at')
                                            ->limit(5)
                                            ->get(['id', 'uuid', 'name', 'price', 'status', 'vendor_id', 'created_at']),
            ];

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
