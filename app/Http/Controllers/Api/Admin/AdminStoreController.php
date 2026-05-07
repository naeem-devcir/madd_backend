<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreVendorStoreRequest;
use App\Http\Resources\VendorStoreResource;
use App\Models\Config\Domain;
use App\Models\Review\Review;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Services\Store\StoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminStoreController extends Controller
{
    public function __construct(protected StoreService $storeService) {}

    // -------------------------------------------------------------------------
    // LISTING
    // -------------------------------------------------------------------------

    /**
     * GET /admin/stores
     *
     * Read mode: local tables only
     * Query params: vendor_id, status, country_code, search, per_page
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = VendorStore::with(['vendor', 'domain', 'theme']);

            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('country_code')) {
                $query->where('country_code', $request->country_code);
            }

            if ($request->filled('search')) {
                $term = '%' . addcslashes($request->search, '%_') . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('store_name', 'like', $term)
                        ->orWhere('store_slug', 'like', $term)
                        ->orWhere('subdomain', 'like', $term);
                });
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $stores  = $query->latest()->paginate($perPage);

            // Scoped counts so filters are reflected in meta
            $countQuery = VendorStore::when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->vendor_id));

            return response()->json([
                'success' => true,
                'data'    => VendorStoreResource::collection($stores),
                'meta'    => [
                    'current_page'  => $stores->currentPage(),
                    'last_page'     => $stores->lastPage(),
                    'per_page'      => $stores->perPage(),
                    'total'         => $stores->total(),
                    'active'        => (clone $countQuery)->where('status', 'active')->count(),
                    'inactive'      => (clone $countQuery)->where('status', 'inactive')->count(),
                ],
            ]);

        } catch (Throwable $e) {
            return $this->serverError('Failed to fetch stores', $e);
        }
    }

    // -------------------------------------------------------------------------
    // CREATE
    // -------------------------------------------------------------------------

    /**
     * POST /admin/stores
     *
     * Write flow: Magento store group first → local VendorStore with magento reference
     * All stores go under website_id = vendor->magento_website_id (default 1 for shared setups)
     */
    public function store(StoreVendorStoreRequest $request): JsonResponse
    {
        try {
            $vendor = Vendor::findOrFail($request->vendor_id);

            $storeLimit        = $this->storeService->getVendorStoreLimit($vendor);
            $currentStoreCount = VendorStore::where('vendor_id', $vendor->id)->count();

            if ($currentStoreCount >= $storeLimit) {
                return response()->json([
                    'success' => false,
                    'message' => "Vendor has reached the maximum store limit of {$storeLimit} for their plan.",
                ], 422);
            }

            $store = $this->storeService->create($request->validated());

            return response()->json([
                'success' => true,
                'message' => "Store created in Magento and Laravel successfully for vendor: {$vendor->company_name}",
                'data'    => new VendorStoreResource($store),
            ], 201);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Failed to create store', $e);
        }
    }

    // -------------------------------------------------------------------------
    // SHOW
    // -------------------------------------------------------------------------

    /**
     * GET /admin/stores/{uuid}
     *
     * Read mode: local tables only
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $store = VendorStore::where('uuid', $uuid)
                ->with(['vendor', 'domain', 'theme', 'products'])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data'    => new VendorStoreResource($store),
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to fetch store', $e);
        }
    }

    // -------------------------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------------------------

    /**
     * PUT /admin/stores/{uuid}
     *
     * Write flow: Magento store group update first → local VendorStore updated
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            $validated = $request->validate([
                'store_name'           => 'sometimes|string|max:255',
                'store_slug'           => [
                    'sometimes', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                    \Illuminate\Validation\Rule::unique('vendor_stores', 'store_slug')->ignore($store->id),
                ],
                'status'               => 'sometimes|in:inactive,active,suspended,maintenance',
                'country_code'         => 'sometimes|string|size:2',
                'language_code'        => 'sometimes|string|size:2',
                'currency_code'        => 'sometimes|string|size:3',
                'subdomain'            => [
                    'nullable', 'string', 'max:100',
                    \Illuminate\Validation\Rule::unique('vendor_stores', 'subdomain')->ignore($store->id),
                ],
                'primary_color'        => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
                'secondary_color'      => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
                'contact_email'        => 'nullable|email',
                'contact_phone'        => 'nullable|string|max:20',
                'theme_id'             => 'nullable|exists:themes,id',
                'sales_policy_id'      => 'nullable|exists:sales_policies,id',
                'logo_url'             => 'nullable|url|max:500',
                'favicon_url'          => 'nullable|url|max:500',
                'banner_url'           => 'nullable|url|max:500',
                'seo_meta_title'       => 'nullable|string|max:255',
                'seo_meta_description' => 'nullable|string|max:500',
                'seo_settings'         => 'nullable|array',
                'payment_methods'      => 'nullable|array',
                'shipping_methods'     => 'nullable|array',
                'tax_settings'         => 'nullable|array',
                'social_links'         => 'nullable|array',
                'google_analytics_id'  => 'nullable|string|max:50',
                'facebook_pixel_id'    => 'nullable|string|max:50',
                'custom_css'           => 'nullable|string',
                'custom_js'            => 'nullable|string',
                'is_demo'              => 'sometimes|boolean',
                'address'              => 'nullable|array',
                'metadata'             => 'nullable|array',
            ]);

            $store = $this->storeService->update($store, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Store updated in Magento and Laravel successfully',
                'data'    => new VendorStoreResource($store),
            ]);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to update store', $e);
        }
    }

    // -------------------------------------------------------------------------
    // DELETE / RESTORE
    // -------------------------------------------------------------------------

    /**
     * DELETE /admin/stores/{uuid}
     *
     * Write flow: Magento store group deleted → local soft-delete
     */
    public function destroy(string $uuid): JsonResponse
    {
        try {
            $store        = VendorStore::where('uuid', $uuid)->firstOrFail();
            $magentoResult = $this->storeService->delete($store);

            return response()->json([
                'success' => true,
                'message' => 'Store deleted from Magento and Laravel successfully',
                'data'    => ['magento' => $magentoResult],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to delete store', $e);
        }
    }

    /**
     * DELETE /admin/stores/{uuid}/force
     *
     * Write flow: Magento store group deleted → local hard-delete with domains
     */
    public function forceDelete(string $uuid): JsonResponse
    {
        try {
            $store        = VendorStore::withTrashed()->where('uuid', $uuid)->firstOrFail();
            $magentoResult = $this->storeService->delete($store, force: true);

            return response()->json([
                'success' => true,
                'message' => 'Store permanently deleted from Magento and Laravel',
                'data'    => ['magento' => $magentoResult],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to permanently delete store', $e);
        }
    }

    /**
     * POST /admin/stores/{uuid}/restore
     *
     * Read/write local only — restores soft-deleted store and its domains
     */
    public function restore(string $uuid): JsonResponse
    {
        try {
            $store = VendorStore::withTrashed()->where('uuid', $uuid)->firstOrFail();

            if (! $store->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store is not deleted, nothing to restore',
                ], 422);
            }

            DB::transaction(function () use ($store) {
                $store->restore();
                Domain::withTrashed()->where('vendor_store_id', $store->id)->restore();
            });

            return response()->json([
                'success' => true,
                'message' => 'Store restored successfully',
                'data'    => new VendorStoreResource($store->fresh(['vendor', 'domain', 'theme'])),
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to restore store', $e);
        }
    }

    // -------------------------------------------------------------------------
    // STATUS SHORTCUTS
    // -------------------------------------------------------------------------

    /**
     * POST /admin/stores/{uuid}/activate
     *
     * Write flow: Magento store group enabled → local status = active
     */
    public function activate(string $uuid): JsonResponse
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            if ($store->status === 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'Store is already active',
                ], 422);
            }

            $store = $this->storeService->update($store, [
                'status'       => 'active',
                'activated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Store activated in Magento and Laravel successfully',
                'data'    => new VendorStoreResource($store),
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to activate store', $e);
        }
    }

    /**
     * POST /admin/stores/{uuid}/deactivate
     *
     * Write flow: Magento store group disabled → local status = inactive
     */
    public function deactivate(string $uuid): JsonResponse
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            if ($store->status === 'inactive') {
                return response()->json([
                    'success' => false,
                    'message' => 'Store is already inactive',
                ], 422);
            }

            $store = $this->storeService->update($store, ['status' => 'inactive']);

            return response()->json([
                'success' => true,
                'message' => 'Store deactivated in Magento and Laravel successfully',
                'data'    => new VendorStoreResource($store),
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to deactivate store', $e);
        }
    }

    // -------------------------------------------------------------------------
    // DOMAIN
    // -------------------------------------------------------------------------

    /**
     * POST /admin/stores/{uuid}/domain
     */
    public function addDomain(Request $request, string $uuid): JsonResponse
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            $validated = $request->validate([
                'domain'         => 'required|string|max:255|unique:domains,domain',
                'type'           => 'sometimes|in:madd_subdomain,vendor_custom,marketplace',
                'verified'       => 'sometimes|boolean',
                'set_as_primary' => 'sometimes|boolean',
            ]);

            $domain = Domain::create([
                'uuid'               => (string) Str::uuid(),
                'vendor_store_id'    => $store->id,
                'domain'             => $validated['domain'],
                'type'               => $validated['type'] ?? 'vendor_custom',
                'verification_token' => Str::random(32),
                'verified_at'        => ! empty($validated['verified']) ? now() : null,
                'is_active'          => true,
            ]);

            if (! $store->domain_id || ! empty($validated['set_as_primary'])) {
                $store->update(['domain_id' => $domain->id]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Domain added successfully',
                'data'    => $domain,
            ]);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to add domain', $e);
        }
    }

    // -------------------------------------------------------------------------
    // STATS
    // -------------------------------------------------------------------------

    /**
     * GET /admin/stores/{uuid}/stats
     *
     * Read mode: local tables only
     */
    public function stats(string $uuid): JsonResponse
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            // Load all order aggregates in a single query to avoid N+1
            $orderStats = DB::table('orders')
                ->where('vendor_store_id', $store->id)
                ->selectRaw("
                    COUNT(*)                                                      AS total,
                    SUM(grand_total)                                              AS revenue,
                    SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END)       AS pending,
                    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END)       AS processing,
                    SUM(CASE WHEN status = 'completed'  THEN 1 ELSE 0 END)       AS completed,
                    SUM(CASE WHEN status = 'cancelled'  THEN 1 ELSE 0 END)       AS cancelled,
                    SUM(CASE WHEN created_at >= ? THEN grand_total ELSE 0 END)   AS last_30_days_revenue
                ", [now()->subDays(30)])
                ->first();

            $totalOrders   = (int)   ($orderStats->total ?? 0);
            $totalRevenue  = (float) ($orderStats->revenue ?? 0);

            $reviewStats = Review::where('vendor_store_id', $store->id)
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
                ->first();

            $ratingBreakdown = Review::where('vendor_store_id', $store->id)
                ->selectRaw('rating, COUNT(*) as count')
                ->groupBy('rating')
                ->orderByDesc('rating')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => [
                    'store_info' => [
                        'id'     => $store->id,
                        'uuid'   => $store->uuid,
                        'name'   => $store->store_name,
                        'slug'   => $store->store_slug,
                        'status' => $store->status,
                    ],
                    'products' => [
                        'total'        => $store->products()->count(),
                        'active'       => $store->products()->where('status', 'active')->count(),
                        'out_of_stock' => $store->products()->where('stock_quantity', '<=', 0)->count(),
                    ],
                    'orders' => [
                        'total'      => $totalOrders,
                        'pending'    => (int) ($orderStats->pending    ?? 0),
                        'processing' => (int) ($orderStats->processing ?? 0),
                        'completed'  => (int) ($orderStats->completed  ?? 0),
                        'cancelled'  => (int) ($orderStats->cancelled  ?? 0),
                    ],
                    'revenue' => [
                        'total'               => $totalRevenue,
                        'average_order_value' => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0,
                        'last_30_days'        => (float) ($orderStats->last_30_days_revenue ?? 0),
                    ],
                    'ratings' => [
                        'average'       => round((float) ($reviewStats->avg_rating ?? 0), 1),
                        'total_reviews' => (int) ($reviewStats->total ?? 0),
                        'by_rating'     => $ratingBreakdown,
                    ],
                ],
            ]);

        } catch (ModelNotFoundException) {
            return $this->notFound($uuid);
        } catch (Throwable $e) {
            return $this->serverError('Failed to fetch store statistics', $e);
        }
    }

    // -------------------------------------------------------------------------
    // VENDOR-SCOPED LISTING
    // -------------------------------------------------------------------------

    /**
     * GET /admin/stores/by-vendor/{vendorId}
     *
     * vendorId can be numeric ID or UUID
     * Read mode: local tables only
     */
    public function getStoresByVendor(string $vendorId): JsonResponse
    {
        try {
            $vendor = is_numeric($vendorId)
                ? Vendor::findOrFail($vendorId)
                : Vendor::where('uuid', $vendorId)->firstOrFail();

            $stores = VendorStore::where('vendor_id', $vendor->id)
                ->with(['domain', 'theme'])
                ->latest()
                ->get();

            return response()->json([
                'success' => true,
                'data'    => [
                    'vendor' => [
                        'id'           => $vendor->id,
                        'uuid'         => $vendor->uuid,
                        'company_name' => $vendor->company_name,
                        'email'        => $vendor->user->email ?? null,
                        'status'       => $vendor->status,
                    ],
                    'stores'             => VendorStoreResource::collection($stores),
                    'total_stores'       => $stores->count(),
                    'active_stores'      => $stores->where('status', 'active')->count(),
                    'max_stores_allowed' => $this->storeService->getVendorStoreLimit($vendor),
                ],
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => "Vendor not found: {$vendorId}",
            ], 404);
        } catch (Throwable $e) {
            return $this->serverError('Failed to retrieve stores', $e);
        }
    }

    // -------------------------------------------------------------------------
    // BULK
    // -------------------------------------------------------------------------

    /**
     * POST /admin/stores/bulk-status
     *
     * Write flow: each store goes through storeService->update (Magento + local)
     * Returns 207 if any stores failed
     */
    public function bulkStatusUpdate(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'store_ids'   => 'required|array|min:1|max:50',
                'store_ids.*' => 'required|integer|exists:vendor_stores,id',
                'status'      => 'required|in:inactive,active,suspended,maintenance',
            ]);

            $updated = [];
            $failed  = [];

            foreach (VendorStore::whereIn('id', $validated['store_ids'])->get() as $store) {
                try {
                    $this->storeService->update($store, ['status' => $validated['status']]);
                    $updated[] = $store->id;
                } catch (Throwable $e) {
                    $failed[] = ['store_id' => $store->id, 'message' => $e->getMessage()];
                }
            }

            $updatedCount = count($updated);
            $status       = empty($failed) ? 200 : 207;

            return response()->json([
                'success' => empty($failed),
                'message' => "{$updatedCount} store(s) updated to '{$validated['status']}'",
                'data'    => [
                    'updated_count' => $updatedCount,
                    'status'        => $validated['status'],
                    'updated'       => $updated,
                    'failed'        => $failed,
                ],
            ], $status);

        } catch (ValidationException $e) {
            return $this->validationError($e);
        } catch (Throwable $e) {
            return $this->serverError('Failed to bulk update stores', $e);
        }
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function notFound(string $uuid): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => "Store not found: {$uuid}",
        ], 404);
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
        report($e);
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
        ], 500);
    }
}