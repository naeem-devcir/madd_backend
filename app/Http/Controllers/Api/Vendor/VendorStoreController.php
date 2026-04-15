<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorStoreResource;
use App\Models\Config\Domain;
use App\Models\Config\SalesPolicy;
use App\Models\Config\Theme;
use App\Models\Review\Review;
use App\Models\Vendor\VendorStore;
use App\Services\Vendor\VendorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VendorStoreController extends Controller
{
    protected $vendorService;

    public function __construct(VendorService $vendorService)
    {
        $this->vendorService = $vendorService;
    }

    /**
     * Get all stores for the vendor
     */
    public function index(Request $request)
    {
        $vendor = $request->user()->vendor;

        $stores = VendorStore::where('vendor_id', $vendor->getKey())
            ->with(['domain', 'theme', 'salesPolicy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => VendorStoreResource::collection($stores),
            'meta' => [
                'total' => $stores->count(),
                'active_count' => $stores->where('status', 'active')->count(),
                'inactive_count' => $stores->where('status', 'inactive')->count(),
            ],
        ]);
    }

    /**
     * Create a new store
     */
    public function store(Request $request)
    {
        $vendor = $request->user()->vendor;

        // Check plan limits
        if (!$vendor->canAddStore()) {

            return response()->json([
                'success' => false,
                'message' => 'Store limit reached for your plan. Maximum ' . $vendor->plan->max_stores . ' stores allowed.',
                'limit' => $vendor->plan->max_stores,
                'current' => VendorStore::where('vendor_id', $vendor->getKey())->count(),
            ], 403);
        }

        $request->validate([
            'store_name' => 'required|string|max:255',
            'country_code' => 'required|string|size:2|exists:countries,iso2',
            'currency_code' => 'required|string|size:3',
            'language_code' => 'required|string|size:2',
            'subdomain' => 'nullable|string|alpha_dash|min:3|max:50|unique:vendor_stores,subdomain',
            'description' => 'nullable|string|max:500',
        ]);
        DB::beginTransaction();

        try {
            // Create store
            $store = $this->vendorService->createStore($vendor, $request->all());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store created successfully',
                'data' => new VendorStoreResource($store->load(['domain', 'theme'])),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create store',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific store
     */
    public function show(Request $request, $uuid)
    {
        $vendor = $request->user()->vendor;

        // $store = VendorStore::where('vendor_id', $vendor->getKey())
        //     ->with(['domain', 'theme', 'salesPolicy', 'products'])
        //     ->findOrFail($id);

        $store = VendorStore::where('vendor_id', $vendor->getKey())
            ->where('uuid', $uuid)  // Sirf UUID se search karo
            ->with(['domain', 'theme', 'salesPolicy', 'products'])
            ->first();

        if (!$store) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with this UUID'
            ], 404);
        }
        // Get store statistics
        $stats = [
            'products_count' => $store->products()->count(),
            'active_products' => $store->products()->where('status', 'active')->count(),
            'orders_count' => $store->orders()->count(),
            'total_revenue' => $store->orders()->sum('grand_total'),
            'average_order_value' => $store->orders()->avg('grand_total') ?? 0,
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'store' => new VendorStoreResource($store),
                'statistics' => $stats,
            ],
        ]);
    }

    /**
     * Update store
     */
    public function update(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        $request->validate([
            'store_name' => ['sometimes', 'string', 'max:255'],

            'language_code' => ['sometimes', 'string', 'size:2'],
            'currency_code' => ['sometimes', 'string', 'size:3'],

            'contact_email' => ['nullable', 'email'],
            'contact_phone' => ['nullable', 'string', 'max:20'],

            'logo_url' => ['nullable', 'url'],
            'banner_url' => ['nullable', 'url'],

            // HEX color validation (fixed & safe)
            'primary_color' => ['nullable', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],
            'secondary_color' => ['nullable', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'],

            'description' => ['nullable', 'string', 'max:500'],

            'seo_meta_title' => ['nullable', 'string', 'max:70'],
            'seo_meta_description' => ['nullable', 'string', 'max:160'],

            'facebook_pixel_id' => ['nullable', 'string', 'max:50'],
            'google_analytics_id' => ['nullable', 'string', 'max:50'],
        ]);

        DB::beginTransaction();

        try {
            $store->update($request->all());

            // Update Magento store if active
            if ($store->magento_store_id && $store->status === 'active') {
                // Sync to Magento
                // \App\Jobs\Store\SyncStoreToMagento::dispatch($store);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store updated successfully',
                'data' => new VendorStoreResource($store->fresh()),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update store',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate store
     */
    public function activate(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())
            ->where('status', 'inactive')
            ->findOrFail($id);

        // Check if domain is verified if using custom domain
        if ($store->domain_id && ! $store->domain->dns_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Domain must be verified before activating store',
                'domain_status' => $store->domain->ssl_status,
            ], 422);
        }

        DB::beginTransaction();

        try {
            $store->activate();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store activated successfully',
                'data' => [
                    'store_url' => $store->store_url,
                    'status' => $store->status,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to activate store',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Deactivate store
     */
    public function deactivate(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())
            ->where('status', 'active')
            ->findOrFail($id);

        DB::beginTransaction();

        try {
            $store->deactivate();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store deactivated successfully',
                'data' => [
                    'status' => $store->status,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate store',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete store
     */
    public function destroy(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        // Check if store has orders
        $hasOrders = $store->orders()->exists();

        if ($hasOrders) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete store with existing orders. Please archive it instead.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Delete associated domain if exists
            if ($store->domain) {
                $store->domain->delete();
            }

            $store->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete store',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add custom domain to store
     */
    public function addDomain(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        $request->validate([
            'domain' => 'required|string|max:253|unique:domains,domain',
            'is_primary' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            // If setting as primary, remove primary from other domains
            if ($request->is_primary) {
                Domain::where('vendor_store_id', $store->id)->update(['is_primary' => false]);
            }

            $domain = Domain::create([
                'vendor_store_id' => $store->id,
                'domain' => $request->domain,
                'type' => 'vendor_custom',
                'is_primary' => $request->is_primary ?? false,
                'dns_verified' => false,
                'ssl_status' => 'pending',
                'verification_token' => Str::random(32),
            ]);

            if ($domain->is_primary || ! $store->domain_id) {
                $store->forceFill(['domain_id' => $domain->id])->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Domain added successfully. Please verify DNS records.',
                'data' => [
                    'domain_id' => $domain->id,
                    'domain' => $domain->domain,
                    'verification_records' => $this->getDnsVerificationRecords($domain),
                    'is_primary' => $domain->is_primary,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add domain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a custom domain from store.
     */
    public function removeDomain(Request $request, $id, $domainId)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        $domain = Domain::where('id', $domainId)
            ->where('vendor_store_id', $store->id)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $wasPrimary = $store->domain_id === $domain->id;

            $domain->delete();

            if ($wasPrimary) {
                $replacement = Domain::where('vendor_store_id', $store->id)
                    ->whereNull('deleted_at')
                    ->orderByDesc('is_primary')
                    ->orderBy('id')
                    ->first();

                $store->forceFill(['domain_id' => $replacement?->id])->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Domain removed successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove domain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify domain DNS
     */
    public function verifyDomain(Request $request, $storeId, $domainId)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())->findOrFail($storeId);

        $domain = Domain::where('id', $domainId)
            ->where('vendor_store_id', $store->id)
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $domain->verifyDns();

            // If DNS is verified and SSL is active, we can proceed
            if ($domain->dns_verified) {
                $domain->issueSslCertificate();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $domain->dns_verified ? 'Domain verified successfully' : 'DNS verification pending',
                'data' => [
                    'dns_verified' => $domain->dns_verified,
                    'ssl_status' => $domain->ssl_status,
                    'can_activate' => $domain->dns_verified && $domain->ssl_status === 'active',
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify domain',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available themes for store
     */
    public function themes(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())->findOrFail($id);

        $themes = Theme::where('is_active', true)
            ->get();

        $currentTheme = $store->theme;

        return response()->json([
            'success' => true,
            'data' => [
                'themes' => $themes,
                'current_theme' => $currentTheme,
                'is_premium_available' => $vendor->plan->getFeatureValue('premium_themes', false),
            ],
        ]);
    }

    /**
     * Apply theme to store
     */
    public function applyTheme(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())->findOrFail($id);

        $request->validate([
            'theme_id' => 'required|exists:themes,id',
        ]);

        $theme = Theme::find($request->theme_id);

        // Check if theme is premium and vendor has access
        if ($theme->is_premium && ! $vendor->plan->getFeatureValue('premium_themes', false)) {
            return response()->json([
                'success' => false,
                'message' => 'Premium themes are not available on your current plan',
            ], 403);
        }

        DB::beginTransaction();

        try {
            $store->theme_id = $theme->id;
            $store->save();

            // Sync to Magento if store is active
            if ($store->status === 'active') {
                // \App\Jobs\Store\SyncThemeToMagento::dispatch($store, $theme);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Theme applied successfully',
                'data' => [
                    'theme' => $theme,
                    'preview_url' => $store->store_url . '?theme_preview=' . $theme->slug,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to apply theme',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available sales policies
     */
    public function salesPolicies(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $store = VendorStore::where('vendor_id', $vendor->getKey())->findOrFail($id);

        $policies = SalesPolicy::where('country_code', $store->country_code)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $policies,
        ]);
    }

    /**
     * Get DNS verification records for domain
     */
    private function getDnsVerificationRecords($domain)
    {
        return [
            [
                'type' => 'TXT',
                'name' => '_madd.' . $domain->domain,
                'value' => 'madd-verification=' . $domain->verification_token,
                'ttl' => 300,
            ],
            [
                'type' => 'CNAME',
                'name' => 'www.' . $domain->domain,
                'value' => 'stores.madd.eu',
                'ttl' => 300,
            ],
        ];
    }

    /**
     * Public store information (no auth required)
     * GET /api/stores/{slug}/info
     */
    public function publicInfo($slug)
    {
        $store = VendorStore::where('store_slug', $slug)
            ->where('status', 'active')
            ->with(['theme', 'salesPolicy'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                    'slug' => $store->store_slug,
                    'description' => $store->description,
                    'logo_url' => $store->logo_url,
                    'banner_url' => $store->banner_url,
                    'primary_color' => $store->primary_color,
                    'secondary_color' => $store->secondary_color,
                    'contact_email' => $store->contact_email,
                    'contact_phone' => $store->contact_phone,
                    'country_code' => $store->country_code,
                    'currency_code' => $store->currency_code,
                    'language_code' => $store->language_code,
                    'created_at' => $store->created_at,
                ],
                'theme' => $store->theme ? [
                    'id' => $store->theme->id,
                    'name' => $store->theme->name,
                    'slug' => $store->theme->slug,
                    'preview_image' => $store->theme->preview_image,
                ] : null,
                'policies' => $store->salesPolicy ? [
                    'return_policy' => $store->salesPolicy->return_policy,
                    'shipping_policy' => $store->salesPolicy->shipping_policy,
                    'terms_of_service' => $store->salesPolicy->terms_of_service,
                    'privacy_policy' => $store->salesPolicy->privacy_policy,
                ] : null,
            ],
        ]);
    }

    /**
     * Public store full details with products (no auth required)
     * GET /api/stores/{slug}
     */
    public function publicShow($slug)
    {
        $store = VendorStore::where('store_slug', $slug)
            ->where('status', 'active')
            ->with(['theme', 'salesPolicy', 'domain'])
            ->firstOrFail();

        $reviewQuery = Review::where('vendor_store_id', $store->id)
            ->where('status', 'approved');

        // Get store statistics for public view
        $stats = [
            'products_count' => $store->products()->where('status', 'active')->count(),
            'rating' => round($reviewQuery->avg('rating') ?? 0, 1),
            'reviews_count' => (clone $reviewQuery)->count(),
            'total_sales' => $store->orders()->where('status', 'completed')->count(),
        ];

        // Get featured products
        $featuredProducts = $store->products()
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();

        // Get categories with product counts
        $categories = $this->getStoreCategories($store->id);

        return response()->json([
            'success' => true,
            'data' => [
                'store' => [
                    'id' => $store->id,
                    'name' => $store->store_name,
                    'slug' => $store->store_slug,
                    'description' => $store->description,
                    'logo_url' => $store->logo_url,
                    'banner_url' => $store->banner_url,
                    'primary_color' => $store->primary_color,
                    'secondary_color' => $store->secondary_color,
                    'contact_email' => $store->contact_email,
                    'contact_phone' => $store->contact_phone,
                    'country_code' => $store->country_code,
                    'currency_code' => $store->currency_code,
                    'language_code' => $store->language_code,
                    'seo_meta_title' => $store->seo_meta_title,
                    'seo_meta_description' => $store->seo_meta_description,
                    'store_url' => $store->store_url,
                    'created_at' => $store->created_at,
                ],
                'statistics' => $stats,
                'theme' => $store->theme ? [
                    'id' => $store->theme->id,
                    'name' => $store->theme->name,
                    'slug' => $store->theme->slug,
                ] : null,
                'featured_products' => $featuredProducts,
                'categories' => $categories,
                'policies' => $store->salesPolicy,
            ],
        ]);
    }

    /**
     * Extract lightweight category data from product metadata when available.
     */
    private function getStoreCategories(string|int $storeId): array
    {
        $store = VendorStore::find($storeId);

        if (! $store) {
            return [];
        }

        return $store->products()
            ->get(['metadata'])
            ->flatMap(function ($product) {
                $categories = data_get($product->metadata, 'categories', []);

                return is_array($categories) ? $categories : [];
            })
            ->filter(fn($category) => is_array($category) && isset($category['id'], $category['name']))
            ->map(fn($category) => [
                'id' => $category['id'],
                'name' => $category['name'],
                'slug' => $category['slug'] ?? Str::slug($category['name']),
            ])
            ->unique('id')
            ->values()
            ->all();
    }
}
