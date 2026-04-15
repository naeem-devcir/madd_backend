<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Config\Courier;
use App\Models\Config\ShippingMethod;
use App\Models\Config\ShippingZone;
use App\Models\Vendor\VendorStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorShippingController extends Controller
{
    /**
     * Get available shipping methods
     */
    public function methods(Request $request)
    {
        $vendor = $request->user()->vendor;

        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
        ]);

        $store = VendorStore::where('id', $request->store_id)
            ->where('vendor_id', $vendor->getKey())
            ->firstOrFail();

        $methods = ShippingMethod::where('vendor_store_id', $store->id)
            ->with('carrier')
            ->orderBy('sort_order')
            ->get();

        // Get available carriers
        $availableCarriers = Courier::where('is_active', true)
            ->whereJsonContains('countries', $store->country_code)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'methods' => $methods,
                'available_carriers' => $availableCarriers,
                'store_country' => $store->country_code,
            ],
        ]);
    }

    /**
     * Update shipping methods
     */
    public function updateMethods(Request $request)
    {
        $vendor = $request->user()->vendor;

        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'methods' => 'required|array',
            'methods.*.carrier_code' => 'required|string',
            'methods.*.method_code' => 'required|string',
            'methods.*.method_name' => 'required|array',
            'methods.*.price' => 'nullable|numeric|min:0',
            'methods.*.free_shipping_threshold' => 'nullable|numeric|min:0',
            'methods.*.estimated_days_min' => 'nullable|integer|min:0',
            'methods.*.estimated_days_max' => 'nullable|integer|min:0',
            'methods.*.is_active' => 'boolean',
        ]);

        $store = VendorStore::where('id', $request->store_id)
            ->where('vendor_id', $vendor->getKey())
            ->firstOrFail();

        DB::beginTransaction();

        try {
            // Delete existing methods not in the new list
            $existingMethodIds = collect($request->methods)
                ->filter(function ($method) {
                    return isset($method['id']);
                })
                ->pluck('id')
                ->toArray();

            ShippingMethod::where('vendor_store_id', $store->id)
                ->whereNotIn('id', $existingMethodIds)
                ->delete();

            // Update or create methods
            foreach ($request->methods as $index => $methodData) {
                $methodData['vendor_store_id'] = $store->id;
                $methodData['sort_order'] = $index;

                if (isset($methodData['id'])) {
                    $method = ShippingMethod::where('id', $methodData['id'])
                        ->where('vendor_store_id', $store->id)
                        ->first();

                    if ($method) {
                        $method->update($methodData);
                    }
                } else {
                    ShippingMethod::create($methodData);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shipping methods updated successfully',
                'data' => ShippingMethod::where('vendor_store_id', $store->id)->get(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update shipping methods',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get shipping zones
     */
    public function zones(Request $request)
    {
        $vendor = $request->user()->vendor;

        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
        ]);

        $store = VendorStore::where('id', $request->store_id)
            ->where('vendor_id', $vendor->getKey())
            ->firstOrFail();

        $zones = ShippingZone::where('vendor_store_id', $store->id)
            ->with('methods')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $zones,
        ]);
    }

    /**
     * Create shipping zone
     */
    public function createZone(Request $request)
    {
        $vendor = $request->user()->vendor;

        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'name' => 'required|string|max:255',
            'countries' => 'required|array|min:1',
            'countries.*' => 'string|size:2',
            'method_ids' => 'nullable|array',
            'method_ids.*' => 'exists:shipping_methods,id',
        ]);

        $store = VendorStore::where('id', $request->store_id)
            ->where('vendor_id', $vendor->getKey())
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $zone = ShippingZone::create([
                'vendor_store_id' => $store->id,
                'name' => $request->name,
                'countries' => $request->countries,
                'is_active' => true,
            ]);

            if ($request->has('method_ids')) {
                $zone->methods()->attach($request->method_ids);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shipping zone created successfully',
                'data' => $zone->load('methods'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create shipping zone',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update shipping zone
     */
    public function updateZone(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $zone = ShippingZone::whereHas('store', function ($q) use ($vendor) {
            $q->where('vendor_id', $vendor->getKey());
        })
            ->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'countries' => 'sometimes|array|min:1',
            'countries.*' => 'string|size:2',
            'method_ids' => 'nullable|array',
            'method_ids.*' => 'exists:shipping_methods,id',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::beginTransaction();

        try {
            $zone->update($request->only(['name', 'countries', 'is_active']));

            if ($request->has('method_ids')) {
                $zone->methods()->sync($request->method_ids);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shipping zone updated successfully',
                'data' => $zone->load('methods'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update shipping zone',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete shipping zone
     */
    public function deleteZone(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $zone = ShippingZone::whereHas('store', function ($q) use ($vendor) {
            $q->where('vendor_id', $vendor->getKey());
        })
            ->findOrFail($id);

        $zone->delete();

        return response()->json([
            'success' => true,
            'message' => 'Shipping zone deleted successfully',
        ]);
    }

    /**
     * Get carrier list
     */
    public function carriers(Request $request)
    {
        $vendor = $request->user()->vendor;

        $carriers = Courier::where('is_active', true)
            ->get(['id', 'name', 'code', 'logo_url', 'tracking_url_template']);

        return response()->json([
            'success' => true,
            'data' => $carriers,
        ]);
    }

    /**
     * Get shipping rates for order
     */
    public function getRates(Request $request)
    {
        $request->validate([
            'store_id' => 'required|exists:vendor_stores,id',
            'destination_country' => 'required|string|size:2',
            'destination_postal_code' => 'nullable|string',
            'weight' => 'nullable|numeric|min:0',
            'subtotal' => 'nullable|numeric|min:0',
        ]);

        // This would calculate real rates based on carrier APIs
        // For now, return mock response
        $rates = [
            [
                'carrier' => 'DHL',
                'service' => 'Express',
                'price' => 19.99,
                'estimated_days' => '1-2',
            ],
            [
                'carrier' => 'DHL',
                'service' => 'Economy',
                'price' => 9.99,
                'estimated_days' => '3-5',
            ],
            [
                'carrier' => 'UPS',
                'service' => 'Standard',
                'price' => 12.99,
                'estimated_days' => '2-4',
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $rates,
        ]);
    }

    /**
     * Track shipment
     */
    public function track(Request $request)
    {
        $request->validate([
            'carrier_id' => 'required|exists:couriers,id',
            'tracking_number' => 'required|string',
        ]);

        $carrier = Courier::find($request->carrier_id);

        // This would call carrier API to get tracking info
        // For now, return mock response
        $tracking = [
            'tracking_number' => $request->tracking_number,
            'carrier' => $carrier->name,
            'status' => 'in_transit',
            'estimated_delivery' => now()->addDays(3)->toDateString(),
            'events' => [
                [
                    'status' => 'picked_up',
                    'location' => 'Warehouse',
                    'description' => 'Package picked up',
                    'timestamp' => now()->subDays(1)->toIso8601String(),
                ],
                [
                    'status' => 'in_transit',
                    'location' => 'Sorting Center',
                    'description' => 'Package in transit',
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $tracking,
        ]);
    }
}

