<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product\ProductDraft;
use App\Models\Product\VendorProduct;
use App\Services\Product\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Throwable;

class AdminProductController extends Controller
{
    public function __construct(protected ProductService $productService) {}

    // -------------------------------------------------------------------------
    // PRODUCT LISTING & DETAIL
    // -------------------------------------------------------------------------

    /**
     * GET /admin/products
     * GET /admin/vendors/{vendorId}/products
     *
     * Query params:
     *   - vendor_id      (int)    filter by vendor — ignored when vendorId route param present
     *   - status         (string) active|inactive|deleted
     *   - search         (string) searches name, sku, magento_sku
     *   - price_min      (numeric)
     *   - price_max      (numeric)
     *   - per_page       (int, max 100, default 20)
     */
    public function index(Request $request, ?int $vendorId = null): JsonResponse
    {
        try {
            $query = VendorProduct::with(['vendor', 'store']);

            // Vendor scope: route param takes priority over query param
            $resolvedVendorId = $vendorId ?? ($request->filled('vendor_id') && is_numeric($request->vendor_id)
                ? (int) $request->vendor_id
                : null);

            if ($resolvedVendorId) {
                $query->where('vendor_id', $resolvedVendorId);
            }

            if ($request->filled('status') && in_array($request->status, ['active', 'inactive', 'deleted'])) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search') && strlen($request->search) >= 2) {
                $term = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhere('magento_sku', 'like', $term);
                });
            }

            if ($request->filled('price_min') && is_numeric($request->price_min)) {
                $query->where('price', '>=', $request->price_min);
            }

            if ($request->filled('price_max') && is_numeric($request->price_max)) {
                $query->where('price', '<=', $request->price_max);
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $products = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => ProductResource::collection($products),
                'meta'    => [
                    'current_page' => $products->currentPage(),
                    'last_page'    => $products->lastPage(),
                    'per_page'     => $products->perPage(),
                    'total'        => $products->total(),
                    'filters'      => array_filter([
                        'vendor_id' => $resolvedVendorId,
                        'status'    => $request->status,
                        'search'    => $request->search,
                        'price_min' => $request->price_min,
                        'price_max' => $request->price_max,
                    ]),
                ],
            ]);

        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to fetch products', $e);
        }
    }

    /**
     * GET /admin/products/{uuid}
     * GET /admin/vendors/{vendorId}/products/{uuid}
     */
    public function show(string $uuid, ?int $vendorId = null): JsonResponse
    {
        try {
            $query = VendorProduct::with(['vendor', 'store', 'draft', 'reviews', 'orderItems'])
                ->where('uuid', $uuid);

            if ($vendorId) {
                $query->where('vendor_id', $vendorId);
            }

            $product = $query->firstOrFail();

            return response()->json([
                'success' => true,
                'data'    => new ProductResource($product),
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($vendorId ? 'Product not found for this vendor' : 'Product not found');
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to fetch product', $e);
        }
    }

    // -------------------------------------------------------------------------
    // CREATE
    // -------------------------------------------------------------------------

    /**
     * POST /admin/products
     * POST /admin/vendors/{vendorId}/products
     *
     * Write flow: Magento first → local VendorProduct with magento reference
     */
    public function store(Request $request, ?int $vendorId = null): JsonResponse
    {
        try {
            // Route param overrides body — prevents spoofing vendor_id via payload
            if ($vendorId) {
                $request->merge(['vendor_id' => $vendorId]);
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

            $product = $this->productService->createAdminProduct($validated, Auth::id());

            return response()->json([
                'success' => true,
                'message' => 'Product created in Magento and linked in Laravel successfully',
                'data'    => new ProductResource($product->load(['vendor', 'store', 'draft'])),
            ], 201);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to create product', $e);
        }
    }

    // -------------------------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------------------------

    /**
     * PUT /admin/products/{uuid}
     * PUT /admin/vendors/{vendorId}/products/{uuid}
     *
     * Write flow: Magento first → local VendorProduct updated with new magento reference
     */
    public function update(Request $request, string $uuid, ?int $vendorId = null): JsonResponse
    {
        try {
            $query = VendorProduct::where('uuid', $uuid);

            if ($vendorId) {
                $query->where('vendor_id', $vendorId);
            }

            $product = $query->firstOrFail();

            $validated = $request->validate([
                'sku'               => [
                    'nullable', 'string', 'max:255',
                    Rule::unique('vendor_products', 'sku')
                        ->where(fn ($q) => $q->where('vendor_id', $product->vendor_id))
                        ->ignore($product->id),
                ],
                'name'              => 'nullable|string|max:500',
                'description'       => 'nullable|string',
                'short_description' => 'nullable|string',
                'price'             => 'nullable|numeric|min:0',
                'status'            => 'nullable|in:active,inactive',
                'quantity'          => 'nullable|integer|min:0',
                'weight'            => 'nullable|numeric|min:0',
                'categories'        => 'nullable|array',
                'attributes'        => 'nullable|array',
                'media_gallery'     => 'nullable|array',
                'seo_data'          => 'nullable|array',
            ]);

            $product = $this->productService->updateAdminProduct($product, $validated, Auth::id());

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

            $result = $this->productService->deleteAdminProduct($product, Auth::id());

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
            $product = $this->productService->approveProduct($draft, Auth::id(), $validated['notes'] ?? null);

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
                    'approved_by'        => Auth::id(),
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
     * POST /admin/products/drafts/{id}/reject
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
            $this->productService->rejectProduct($draft, Auth::id(), $validated['reason']);

            return response()->json([
                'success' => true,
                'message' => 'Product rejected successfully',
                'data'    => [
                    'draft_id'    => $draft->id,
                    'status'      => 'rejected',
                    'reason'      => $validated['reason'],
                    'rejected_by' => Auth::id(),
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

            $this->productService->requestModification($draft, Auth::id(), $validated['notes']);

            return response()->json([
                'success' => true,
                'message' => 'Modification requested successfully',
                'data'    => [
                    'draft_id'      => $draft->id,
                    'status'        => 'needs_modification',
                    'notes'         => $validated['notes'],
                    'requested_by'  => Auth::id(),
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
     * POST /admin/products/drafts/bulk-approve
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

                    $this->productService->approveProduct($draft, Auth::id(), $validated['notes'] ?? null);
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
                'success'      => true,
                'data'         => $stats,
                'generated_at' => now(),
            ]);

        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to fetch statistics', $e);
        }
    }

    // -------------------------------------------------------------------------
    // FEATURE / UNFEATURE  (stubs — ready to implement)
    // -------------------------------------------------------------------------

    /**
     * POST /admin/products/{uuid}/feature
     */
    public function feature(string $uuid): JsonResponse
    {
        try {
            $product = VendorProduct::where('uuid', $uuid)->firstOrFail();

            if ($product->status !== 'active') {
                return response()->json([
                    'success'        => false,
                    'message'        => 'Only active products can be featured',
                    'current_status' => $product->status,
                ], 422);
            }

            // TODO: $product->update(['is_featured' => true]);
            return response()->json([
                'success' => false,
                'message' => 'Product featuring is not implemented yet',
                'product_id' => $product->uuid,
            ], 501);

        } catch (ModelNotFoundException) {
            return $this->notFound('Product not found');
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to feature product', $e);
        }
    }

    /**
     * POST /admin/products/{uuid}/unfeature
     */
    public function unfeature(string $uuid): JsonResponse
    {
        try {
            $product = VendorProduct::where('uuid', $uuid)->firstOrFail();

            // TODO: $product->update(['is_featured' => false]);
            return response()->json([
                'success'    => false,
                'message'    => 'Product unfeaturing is not implemented yet',
                'product_id' => $product->uuid,
            ], 501);

        } catch (ModelNotFoundException) {
            return $this->notFound('Product not found');
        } catch (Throwable $e) {
            report($e);
            return $this->serverError('Failed to unfeature product', $e);
        }
    }

    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 404);
    }

    private function validationError(ValidationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors'  => $e->errors(),
        ], 422);
    }

    private function serverError(string $message, Throwable $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
        ], 500);
    }
}