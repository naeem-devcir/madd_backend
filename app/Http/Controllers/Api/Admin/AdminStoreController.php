<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreVendorStoreRequest;
use App\Http\Resources\VendorStoreResource;
use App\Models\Config\Domain;
use App\Models\Config\SalesPolicy;
use App\Models\Config\Theme;
use App\Models\Review\Review;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AdminStoreController extends Controller
{
    /**
     * Get ALL stores (global)
     */
    public function index(Request $request)
    {
        try {
            $query = VendorStore::with(['vendor', 'domain', 'theme']);

            // Apply filters
            if ($request->has('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('country_code')) {
                $query->where('country_code', $request->country_code);
            }

            if ($request->has('search')) {
                $query->where(function ($q) use ($request) {
                    $q->where('store_name', 'like', '%' . $request->search . '%')
                        ->orWhere('store_slug', 'like', '%' . $request->search . '%')
                        ->orWhere('subdomain', 'like', '%' . $request->search . '%');
                });
            }

            $stores = $query->latest()->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => VendorStoreResource::collection($stores),
                'meta' => [
                    'current_page' => $stores->currentPage(),
                    'last_page' => $stores->lastPage(),
                    'total' => $stores->total(),
                    'per_page' => $stores->perPage(),
                    'total_records' => VendorStore::count(),
                    'active' => VendorStore::where('status', 'active')->count(),
                    'inactive' => VendorStore::where('status', 'inactive')->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch stores', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stores: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new store for a vendor (admin adds store manually)
     */
    public function store(StoreVendorStoreRequest $request)
    {
        DB::beginTransaction();

        try {
            // Check if vendor exists and is valid
            $vendor = Vendor::findOrFail($request->vendor_id);

            // Check if vendor has reached store limit based on their plan
            $storeLimit = $this->getVendorStoreLimit($vendor);
            $currentStoreCount = VendorStore::where('vendor_id', $vendor->id)->count();

            if ($currentStoreCount >= $storeLimit) {
                return response()->json([
                    'success' => false,
                    'message' => "Vendor has reached the maximum store limit of {$storeLimit} stores based on their plan.",
                ], 422);
            }

            // Generate unique slug if not provided or if duplicate
            $slug = $request->store_slug;
            if (VendorStore::where('store_slug', $slug)->exists()) {
                $slug = $slug . '-' . Str::random(4);
            }

            // Handle subdomain uniqueness
            $subdomain = $request->subdomain;
            if ($subdomain && VendorStore::where('subdomain', $subdomain)->exists()) {
                $subdomain = $subdomain . '-' . Str::random(3);
            }

            // Create the store
            $store = VendorStore::create([
                'uuid' => (string) Str::uuid(),
                'vendor_id' => $vendor->id,
                'store_name' => $request->store_name,
                'store_slug' => $slug,
                'country_code' => $request->country_code,
                'language_code' => $request->language_code ?? 'en',
                'currency_code' => $request->currency_code ?? 'EUR',
                'timezone' => $request->timezone ?? $vendor->timezone ?? 'UTC',
                'subdomain' => $subdomain,
                'domain_id' => $request->domain_id,
                'magento_store_id' => $request->magento_store_id,
                'magento_store_group_id' => $request->magento_store_group_id,
                'magento_website_id' => $request->magento_website_id,
                'theme_id' => $request->theme_id,
                'status' => $request->status ?? 'inactive',
                'sales_policy_id' => $request->sales_policy_id,
                'logo_url' => $request->logo_url,
                'favicon_url' => $request->favicon_url,
                'banner_url' => $request->banner_url,
                'primary_color' => $request->primary_color ?? '#000000',
                'secondary_color' => $request->secondary_color ?? '#666666',
                'contact_email' => $request->contact_email ?? $vendor->contact_email,
                'contact_phone' => $request->contact_phone ?? $vendor->phone,
                'seo_meta_title' => $request->seo_meta_title,
                'seo_meta_description' => $request->seo_meta_description,
                'seo_settings' => $request->seo_settings,
                'payment_methods' => $request->payment_methods,
                'shipping_methods' => $request->shipping_methods,
                'tax_settings' => $request->tax_settings,
                'social_links' => $request->social_links,
                'google_analytics_id' => $request->google_analytics_id,
                'facebook_pixel_id' => $request->facebook_pixel_id,
                'custom_css' => $request->custom_css,
                'custom_js' => $request->custom_js,
                'is_demo' => $request->is_demo ?? false,
                'address' => $request->address,
                'metadata' => $request->metadata,
                'activated_at' => $request->status === 'active' ? now() : null,
            ]);

            // If status is active, set activated_at timestamp
            if ($request->status === 'active') {
                $store->update(['activated_at' => now()]);
            }

            // If domain is provided, create domain record with correct enum value
            if ($request->has('domain') && $request->domain) {
                // Check if domain already exists
                $existingDomain = Domain::where('domain', $request->domain)->first();

                if (!$existingDomain) {
                    $domain = Domain::create([
                        'uuid' => (string) Str::uuid(),
                        'vendor_store_id' => $store->id,
                        'domain' => $request->domain,
                        'type' => 'vendor_custom',
                        'verification_token' => Str::random(32),
                        'verified_at' => null,
                        'is_active' => true,
                    ]);
                    $store->update(['domain_id' => $domain->id]);
                } else {
                    // Domain exists, just associate it
                    $store->update(['domain_id' => $existingDomain->id]);
                }
            }

            // If subdomain is provided, also create a domain record for it
            if ($subdomain && !$request->has('domain')) {
                $subdomainDomain = Domain::create([
                    'uuid' => (string) Str::uuid(),
                    'vendor_store_id' => $store->id,
                    'domain' => $subdomain . '.' . config('app.domain', 'example.com'),
                    'type' => 'madd_subdomain',
                    'verification_token' => Str::random(32),
                    'verified_at' => now(),
                    'is_active' => true,
                ]);

                // Only set as domain_id if no custom domain was provided
                if (!$store->domain_id) {
                    $store->update(['domain_id' => $subdomainDomain->id]);
                }
            }

            DB::commit();

            // Load relationships for response
            $store->load(['vendor', 'domain', 'theme']);

            return response()->json([
                'success' => true,
                'message' => 'Store created successfully for vendor: ' . $vendor->company_name,
                'data' => new VendorStoreResource($store),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create store', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create store: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get vendor's store limit based on their plan
     */
    private function getVendorStoreLimit(Vendor $vendor): int
    {
        if ($vendor->plan && isset($vendor->plan->max_stores)) {
            return $vendor->plan->max_stores;
        }

        return 1; // Default limit
    }

    /**
     * Show store (Admin can access ANY store) - Using UUID
     */
    public function show($uuid)
    {
        try {
            $store = VendorStore::where('uuid', $uuid)
                ->with(['vendor', 'domain', 'theme', 'products'])
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => new VendorStoreResource($store)
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch store', [
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch store: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update store (admin override) - Using UUID
     */
    public function update(Request $request, $uuid)
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            $request->validate([
                'store_name' => 'sometimes|string|max:255',
                'store_slug' => 'sometimes|string|max:255|regex:/^[a-z0-9-]+$/|unique:vendor_stores,store_slug,' . $store->id,
                'status' => 'sometimes|in:inactive,active,suspended,maintenance',
                'country_code' => 'sometimes|string|size:2',
                'language_code' => 'sometimes|string|size:2',
                'currency_code' => 'sometimes|string|size:3',
                'subdomain' => 'nullable|string|max:100|unique:vendor_stores,subdomain,' . $store->id,
                'primary_color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
                'secondary_color' => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
                'contact_email' => 'nullable|email',
                'contact_phone' => 'nullable|string|max:20',
            ]);

            DB::beginTransaction();

            // If status is changing to active, set activated_at
            $updateData = $request->all();
            if ($request->has('status') && $request->status === 'active' && $store->status !== 'active') {
                $updateData['activated_at'] = now();
            }

            $store->update($updateData);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store updated successfully',
                'data' => new VendorStoreResource($store->fresh(['vendor', 'domain', 'theme']))
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update store: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete store (admin) - Soft delete - Using UUID
     */
    public function destroy($uuid)
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            DB::beginTransaction();

            // Delete all domains for this store
            Domain::where('vendor_store_id', $store->id)->delete();

            // Soft delete the store
            $store->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store deleted successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete store: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force delete store (permanent) - Using UUID
     */
    public function forceDelete($uuid)
    {
        try {
            $store = VendorStore::withTrashed()->where('uuid', $uuid)->firstOrFail();

            DB::beginTransaction();

            // Force delete all associated domains
            Domain::where('vendor_store_id', $store->id)->forceDelete();

            // Force delete the store
            $store->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store permanently deleted'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to permanently delete store: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Restore soft-deleted store - Using UUID
     */
    public function restore($uuid)
    {
        try {
            $store = VendorStore::withTrashed()->where('uuid', $uuid)->firstOrFail();

            DB::beginTransaction();

            $store->restore();

            // Restore associated domains
            Domain::withTrashed()->where('vendor_store_id', $store->id)->restore();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store restored successfully',
                'data' => new VendorStoreResource($store->fresh(['vendor', 'domain', 'theme']))
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to restore store: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate store (admin force) - Using UUID
     */
    public function activate($uuid)
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            $store->update([
                'status' => 'active',
                'activated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Store activated successfully',
                'data' => new VendorStoreResource($store->fresh(['vendor', 'domain', 'theme']))
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate store: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate store - Using UUID
     */
    public function deactivate($uuid)
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            $store->update(['status' => 'inactive']);

            return response()->json([
                'success' => true,
                'message' => 'Store deactivated successfully',
                'data' => new VendorStoreResource($store->fresh(['vendor', 'domain', 'theme']))
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate store: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin add domain to store - Using UUID
     */
    public function addDomain(Request $request, $uuid)
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            $request->validate([
                'domain' => 'required|string|unique:domains,domain',
                'verified' => 'sometimes|boolean',
                'type' => 'sometimes|in:madd_subdomain,vendor_custom,marketplace',
                'set_as_primary' => 'sometimes|boolean'
            ]);

            $domainType = $request->type ?? 'vendor_custom';

            // Validate that the type is one of the allowed enum values
            if (!in_array($domainType, ['madd_subdomain', 'vendor_custom', 'marketplace'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid domain type. Allowed types: madd_subdomain, vendor_custom, marketplace'
                ], 422);
            }

            $domain = Domain::create([
                'uuid' => (string) Str::uuid(),
                'vendor_store_id' => $store->id,
                'domain' => $request->domain,
                'type' => $domainType,
                'verification_token' => Str::random(32),
                'verified_at' => $request->verified ? now() : null,
                'is_active' => true,
            ]);

            // Update store's domain_id if this is the primary domain
            if (!$store->domain_id || $request->has('set_as_primary')) {
                $store->update(['domain_id' => $domain->id]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Domain added successfully',
                'data' => $domain
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add domain: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get store analytics and statistics - Using UUID
     */
    public function stats($uuid)
    {
        try {
            $store = VendorStore::where('uuid', $uuid)->firstOrFail();

            $reviewQuery = Review::where('vendor_store_id', $store->id);
            $orders = $store->orders();

            $totalRevenue = $orders->sum('grand_total');
            $totalOrders = $orders->count();
            $averageOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'store_info' => [
                        'id' => $store->id,
                        'uuid' => $store->uuid,
                        'name' => $store->store_name,
                        'slug' => $store->store_slug,
                        'status' => $store->status,
                    ],
                    'products' => [
                        'total' => $store->products()->count(),
                        'active' => $store->products()->where('status', 'active')->count(),
                        'out_of_stock' => $store->products()->where('stock_quantity', '<=', 0)->count(),
                    ],
                    'orders' => [
                        'total' => $totalOrders,
                        'pending' => $orders->clone()->where('status', 'pending')->count(),
                        'processing' => $orders->clone()->where('status', 'processing')->count(),
                        'completed' => $orders->clone()->where('status', 'completed')->count(),
                        'cancelled' => $orders->clone()->where('status', 'cancelled')->count(),
                    ],
                    'revenue' => [
                        'total' => $totalRevenue,
                        'average_order_value' => round($averageOrderValue, 2),
                        'last_30_days' => $orders->clone()->where('created_at', '>=', now()->subDays(30))->sum('grand_total'),
                    ],
                    'ratings' => [
                        'average' => round($reviewQuery->avg('rating') ?? 0, 1),
                        'total_reviews' => $reviewQuery->count(),
                        'by_rating' => $reviewQuery->selectRaw('rating, count(*) as count')
                            ->groupBy('rating')
                            ->orderBy('rating', 'desc')
                            ->get()
                    ],
                ]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Store not found with UUID: ' . $uuid,
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to fetch store stats', [
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch store statistics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get stores by vendor UUID
     */
    public function getStoresByVendor($vendorUuid)
    {
        try {
            // Find vendor by UUID
            $vendor = Vendor::where('uuid', $vendorUuid)->firstOrFail();

            // Get stores using vendor's internal ID
            $stores = VendorStore::where('vendor_id', $vendor->id)
                ->with(['domain', 'theme'])
                ->latest()
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'vendor' => [
                        'id' => $vendor->id,
                        'uuid' => $vendor->uuid,
                        'company_name' => $vendor->company_name,
                        'email' => $vendor->user->email ?? null,
                        'status' => $vendor->status,
                    ],
                    'stores' => VendorStoreResource::collection($stores),
                    'total_stores' => $stores->count(),
                    'active_stores' => $stores->where('status', 'active')->count(),
                    'max_stores_allowed' => $this->getVendorStoreLimit($vendor),
                ]
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Vendor not found with UUID: ' . $vendorUuid,
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to get stores by vendor', [
                'vendor_uuid' => $vendorUuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stores: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk update store status (uses IDs, not UUIDs for bulk operations)
     */
    public function bulkStatusUpdate(Request $request)
    {
        try {
            $request->validate([
                'store_ids' => 'required|array',
                'store_ids.*' => 'exists:vendor_stores,id',
                'status' => 'required|in:inactive,active,suspended,maintenance'
            ]);

            DB::beginTransaction();

            $updatedCount = VendorStore::whereIn('id', $request->store_ids)
                ->update(['status' => $request->status]);

            // If status is active, update activated_at for those stores
            if ($request->status === 'active') {
                VendorStore::whereIn('id', $request->store_ids)
                    ->whereNull('activated_at')
                    ->update(['activated_at' => now()]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "{$updatedCount} stores have been updated to {$request->status} status",
                'data' => [
                    'updated_count' => $updatedCount,
                    'status' => $request->status
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update stores: ' . $e->getMessage()
            ], 500);
        }
    }
}