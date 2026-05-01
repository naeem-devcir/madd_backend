<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorStoreResource;
use App\Models\Config\Domain;
use App\Models\Config\SalesPolicy;
use App\Models\Config\Theme;
use App\Models\Review\Review;
use App\Models\Vendor\VendorStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminStoreController extends Controller
{
    /**
     * Get ALL stores (global)
     */
    public function index()
    {
        $stores = VendorStore::with(['vendor', 'domain', 'theme'])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => VendorStoreResource::collection($stores),
            'meta' => [
                'total' => $stores->count(),
                'active' => $stores->where('status', 'active')->count(),
                'inactive' => $stores->where('status', 'inactive')->count(),
            ]
        ]);
    }

    /**
     * Show store (Admin can access ANY store)
     */
    public function show($uuid)
    {
        $store = VendorStore::where('uuid', $uuid)
            ->with(['vendor', 'domain', 'theme', 'products'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new VendorStoreResource($store)
        ]);
    }


    /**
     * Create a new store (admin)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'vendor_id' => 'required|exists:vendors,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:vendor_stores,slug|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|string|max:255',
            'banner' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'zip_code' => 'nullable|string|max:20',
            'country_code' => 'nullable|string|size:2',
            'currency' => 'nullable|string|size:3',
            'timezone' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,inactive,pending',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'facebook_url' => 'nullable|url|max:255',
            'instagram_url' => 'nullable|url|max:255',
            'twitter_url' => 'nullable|url|max:255',
            'whatsapp_number' => 'nullable|string|max:20',
        ]);

        DB::beginTransaction();

        try {
            // Generate UUID if not provided
            $validated['uuid'] = (string) Str::uuid();

            // Set default status if not provided
            if (!isset($validated['status'])) {
                $validated['status'] = 'pending';
            }

            // Create the store
            $store = VendorStore::create($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store created successfully by admin',
                'data' => new VendorStoreResource($store->load(['vendor', 'domain', 'theme'])),
            ], 201);
        } catch (\Illuminate\Database\QueryException $e) {
            DB::rollBack();

            if ($e->errorInfo[1] == 1062) { // Duplicate entry
                return response()->json([
                    'success' => false,
                    'message' => 'Store slug already exists. Please use a different slug.',
                ], 422);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to create store: ' . $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create store: ' . $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Update store (admin override)
     */
    public function update(Request $request, $id)
    {
        $store = VendorStore::findOrFail($id);

        DB::beginTransaction();

        try {
            $store->update($request->all());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store updated by admin',
                'data' => new VendorStoreResource($store)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete store (admin)
     */
    public function destroy($id)
    {
        $store = VendorStore::findOrFail($id);

        DB::beginTransaction();

        try {
            if ($store->domain) {
                $store->domain->delete();
            }

            $store->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Store deleted by admin'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate store (admin force)
     */
    public function activate($id)
    {
        $store = VendorStore::findOrFail($id);

        $store->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => 'Store activated by admin'
        ]);
    }

    /**
     * Deactivate store
     */
    public function deactivate($id)
    {
        $store = VendorStore::findOrFail($id);

        $store->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'Store deactivated by admin'
        ]);
    }

    /**
     * Admin add domain
     */
    public function addDomain(Request $request, $id)
    {
        $store = VendorStore::findOrFail($id);

        $request->validate([
            'domain' => 'required|unique:domains,domain'
        ]);

        $domain = Domain::create([
            'vendor_store_id' => $store->id,
            'domain' => $request->domain,
            'type' => 'admin_added',
            'verification_token' => Str::random(32)
        ]);

        return response()->json([
            'success' => true,
            'data' => $domain
        ]);
    }

    /**
     * Get stats (admin analytics)
     */
    public function stats($id)
    {
        $store = VendorStore::findOrFail($id);

        $reviewQuery = Review::where('vendor_store_id', $store->id);

        return response()->json([
            'products' => $store->products()->count(),
            'orders' => $store->orders()->count(),
            'revenue' => $store->orders()->sum('grand_total'),
            'rating' => round($reviewQuery->avg('rating') ?? 0, 1),
        ]);
    }
}
