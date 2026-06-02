<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorStore;
use App\Services\Order\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    protected CartService $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    /**
     * Get available payment methods
     * 
     * GET /api/admin/carts/payment-methods
     */
    public function getPaymentMethods(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
                'customer_id' => 'required|integer|min:1',
            ]);

            $vendor = Vendor::where('uuid', $validated['vendor_uuid'])->firstOrFail();
            $store = VendorStore::where('uuid', $validated['store_uuid'])->firstOrFail();

            // Verify store belongs to vendor
            if ($store->vendor_id !== $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }

            $paymentMethods = $this->cartService->getPaymentMethods(
                $vendor,
                $store,
                (int) $validated['customer_id']
            );

            return response()->json([
                'success' => true,
                'data' => $paymentMethods,
                'message' => 'Payment methods retrieved successfully',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch payment methods', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment methods',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get available shipping methods
     * 
     * POST /api/admin/carts/shipping-methods
     */
    public function getShippingMethods(Request $request)
    {
        try {
            $validated = $request->validate([
                'vendor_uuid' => 'required|string|exists:vendors,uuid',
                'store_uuid' => 'required|string|exists:vendor_stores,uuid',
                'customer_id' => 'required|integer|min:1',
                'shipping_address' => 'required|array',
                'shipping_address.country_id' => 'required|string|max:2',
                'shipping_address.region' => 'nullable|string|max:100',
                'shipping_address.city' => 'nullable|string|max:100',
                'shipping_address.postcode' => 'nullable|string|max:30',
                'shipping_address.street' => 'nullable|string|max:500',
                'shipping_address.firstname' => 'nullable|string|max:100',
                'shipping_address.lastname' => 'nullable|string|max:100',
                'shipping_address.telephone' => 'nullable|string|max:50',
            ]);

            $vendor = Vendor::where('uuid', $validated['vendor_uuid'])->firstOrFail();
            $store = VendorStore::where('uuid', $validated['store_uuid'])->firstOrFail();

            // Verify store belongs to vendor
            if ($store->vendor_id !== $vendor->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store does not belong to the specified vendor',
                ], 403);
            }

            $shippingMethods = $this->cartService->getShippingMethods(
                $vendor,
                $store,
                (int) $validated['customer_id'],
                $validated['shipping_address']
            );

            return response()->json([
                'success' => true,
                'data' => $shippingMethods,
                'message' => 'Shipping methods retrieved successfully',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to fetch shipping methods', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch shipping methods',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}