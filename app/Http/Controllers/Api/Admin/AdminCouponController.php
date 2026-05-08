<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Config\Coupon;
use App\Services\Promotion\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class AdminCouponController extends Controller
{
    protected $couponService;

    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    /**
     * Get all coupons (READ ONLY - from local DB)
     */
    public function index(Request $request)
    {
        $query = Coupon::with(['vendor']);

        // Apply filters
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('discount_type')) {
            $query->where('discount_type', $request->discount_type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('code', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->has('valid')) {
            $query->active();
        }

        $coupons = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $coupons,
            'meta' => [
                'current_page' => $coupons->currentPage(),
                'last_page' => $coupons->lastPage(),
                'total' => $coupons->total(),
            ],
        ]);
    }

    /**
     * Create a new coupon - Magento First
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:coupons,code',
            'description' => 'nullable|string',
            'type' => ['required', Rule::in(['platform', 'vendor'])],
            'vendor_id' => 'required_if:type,vendor|exists:vendors,uuid|nullable',
            'discount_type' => ['required', Rule::in(['percentage', 'fixed_amount', 'free_shipping', 'buy_x_get_y'])],
            'discount_value' => 'required_if:discount_type,percentage,fixed_amount|numeric|min:0',
            'min_order_amount' => 'nullable|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'per_customer_limit' => 'nullable|integer|min:1',
            'usage_limit_per_transaction' => 'integer|min:1',
            'exclude_sale_items' => 'boolean',
            'allowed_emails' => 'nullable|array',
            'allowed_emails.*' => 'email',
            'allowed_roles' => 'nullable|array',
            'allowed_roles.*' => 'string',
            'budget_limit' => 'nullable|numeric|min:0',
            'applicable_to' => ['required', Rule::in(['all', 'products', 'vendors', 'stores'])],
            'applicable_ids' => 'nullable|array',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at',
            'is_active' => 'boolean',
        ]);

        try {
            // Service handles: Magento first, then local DB
            $coupon = $this->couponService->createCoupon($validated);

            return response()->json([
                'success' => true,
                'message' => 'Coupon created successfully in Magento and local database',
                'data' => $coupon->load('vendor'),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create coupon: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get single coupon (READ ONLY - from local DB)
     */
    public function show($id)
    {
        try {
            $coupon = Coupon::with(['vendor', 'orders'])->findOrFail($id);

            $usageStats = [
                'total_uses' => $coupon->used_count,
                'total_discount' => $coupon->spent_amount,
                'remaining_budget' => $coupon->budget_limit ? $coupon->budget_limit - $coupon->spent_amount : null,
                'remaining_uses' => $coupon->max_uses ? $coupon->max_uses - $coupon->used_count : null,
                'average_discount' => $coupon->used_count > 0 ? $coupon->spent_amount / $coupon->used_count : 0,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'coupon' => $coupon,
                    'usage_statistics' => $usageStats,
                    'recent_orders' => $coupon->orders()->latest()->limit(10)->get(),
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found',
            ], 404);
        }
    }

    /**
     * Update coupon - Magento First
     */
    public function update(Request $request, $id)
    {

        try {
            $coupon = Coupon::where('uuid', $id)->firstOrFail();
            $validated = $request->validate([
                'code' => 'sometimes|string|max:50|unique:coupons,code,' . $coupon->id,
                'description' => 'nullable|string',
                'discount_type' => ['sometimes', Rule::in(['percentage', 'fixed_amount', 'free_shipping', 'buy_x_get_y'])],
                'discount_value' => 'required_if:discount_type,percentage,fixed_amount|numeric|min:0',
                'min_order_amount' => 'nullable|numeric|min:0',
                'max_uses' => 'nullable|integer|min:1',
                'per_customer_limit' => 'nullable|integer|min:1',
                'usage_limit_per_transaction' => 'integer|min:1',
                'exclude_sale_items' => 'boolean',
                'allowed_emails' => 'nullable|array',
                'allowed_emails.*' => 'email',
                'allowed_roles' => 'nullable|array',
                'budget_limit' => 'nullable|numeric|min:0',
                'applicable_to' => ['sometimes', Rule::in(['all', 'products', 'vendors', 'stores'])],
                'applicable_ids' => 'nullable|array',
                'starts_at' => 'nullable|date',
                'expires_at' => 'nullable|date|after:starts_at',
                'is_active' => 'boolean',
            ]);

            // Service handles: Magento first, then local DB
            $updatedCoupon = $this->couponService->updateCoupon($coupon, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Coupon updated successfully in Magento and local database',
                'data' => $updatedCoupon,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update coupon: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete coupon - Magento First
     */
    public function destroy($id)
    {
        try {
            $coupon = Coupon::where('uuid', $id)->firstOrFail();

            // Service handles: Magento first, then local DB
            $this->couponService->deleteCoupon($coupon);

            return response()->json([
                'success' => true,
                'message' => 'Coupon deleted successfully from Magento and local database',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle coupon status - Magento First
     */
    public function toggleStatus($id)
    {
        try {
            $coupon = Coupon::where('uuid', $id)->firstOrFail();

            // Service handles: Magento first, then local DB
            $updatedCoupon = $this->couponService->toggleStatus($coupon);

            return response()->json([
                'success' => true,
                'message' => $updatedCoupon->is_active ? 'Coupon activated in Magento and local DB' : 'Coupon deactivated in Magento and local DB',
                'data' => [
                    'is_active' => $updatedCoupon->is_active,
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle coupon status: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resync coupon from Magento (pull latest data)
     * Useful when local DB might be out of sync
     */
    public function resyncFromMagento($id)
    {
        try {
            $coupon = Coupon::where('uuid', $id)->firstOrFail();

            if ($coupon->type !== 'platform') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only platform coupons can be resynced from Magento',
                ], 422);
            }

            $resyncedCoupon = $this->couponService->resyncFromMagento($coupon);

            return response()->json([
                'success' => true,
                'message' => 'Coupon resynced successfully from Magento',
                'data' => $resyncedCoupon,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resync coupon: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Duplicate coupon - Local only (no Magento sync)
     * Creates a copy in local DB without syncing to Magento
     * User can sync manually later if needed
     */
    public function duplicate($id)
    {
        try {
            $originalCoupon = Coupon::where('uuid', $id)->firstOrFail();

            $newCoupon = $originalCoupon->replicate();
            $newCoupon->code = $originalCoupon->code . '_copy_' . time();
            $newCoupon->used_count = 0;
            $newCoupon->spent_amount = 0;
            $newCoupon->magento_rule_id = null;
            $newCoupon->magento_coupon_id = null;
            $newCoupon->sync_status = 'pending';
            $newCoupon->is_active = false;

            // For platform coupons, we won't sync automatically
            // User must manually sync via the sync endpoint
            if ($originalCoupon->type === 'platform') {
                $newCoupon->sync_status = 'pending';
            }

            $newCoupon->save();

            return response()->json([
                'success' => true,
                'message' => $originalCoupon->type === 'platform'
                    ? 'Coupon duplicated. Use sync endpoint to push to Magento.'
                    : 'Coupon duplicated successfully',
                'data' => $newCoupon,
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found',
            ], 404);
        }
    }

    /**
     * Manual sync - Push to Magento (for pending/failed coupons)
     */
    public function syncToMagento($id)
    {
        try {
            $coupon = Coupon::where('uuid', $id)->firstOrFail();

            if ($coupon->type !== 'platform') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only platform coupons can be synced to Magento',
                ], 422);
            }

            if ($coupon->sync_status === 'synced' && $coupon->magento_rule_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Coupon is already synced to Magento',
                    'data' => $coupon,
                ], 422);
            }

            // If coupon has Magento IDs, update; otherwise create new
            if ($coupon->magento_rule_id) {
                $updatedCoupon = $this->couponService->updateCoupon($coupon, $coupon->toArray());
            } else {
                $updatedCoupon = $this->couponService->createCoupon($coupon->toArray());
            }

            return response()->json([
                'success' => true,
                'message' => 'Coupon synced to Magento successfully',
                'data' => $updatedCoupon,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync coupon: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get coupon statistics (READ ONLY - from local DB)
     */
    public function statistics()
    {
        $stats = [
            'total' => Coupon::count(),
            'active' => Coupon::where('is_active', true)->count(),
            'inactive' => Coupon::where('is_active', false)->count(),
            'platform_coupons' => Coupon::where('type', 'platform')->count(),
            'vendor_coupons' => Coupon::where('type', 'vendor')->count(),
            'synced' => Coupon::where('sync_status', 'synced')->count(),
            'pending_sync' => Coupon::where('sync_status', 'pending')->count(),
            'failed_sync' => Coupon::where('sync_status', 'failed')->count(),
            'by_discount_type' => Coupon::select('discount_type', DB::raw('count(*) as count'))
                ->groupBy('discount_type')
                ->get(),
            'total_uses' => Coupon::sum('used_count'),
            'total_discount_given' => Coupon::sum('spent_amount'),
            'top_coupons' => Coupon::orderBy('used_count', 'desc')
                ->limit(10)
                ->get(['code', 'used_count', 'spent_amount']),
            'expiring_soon' => Coupon::where('expires_at', '<=', now()->addDays(30))
                ->where('expires_at', '>', now())
                ->where('is_active', true)
                ->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Export coupons to CSV (READ ONLY - from local DB)
     */
    public function export(Request $request)
    {
        $query = Coupon::with(['vendor']);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $coupons = $query->get();

        $filename = 'coupons_export_' . date('Y-m-d_His') . '.csv';
        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'ID',
            'Code',
            'Type',
            'Discount Type',
            'Discount Value',
            'Min Order',
            'Max Uses',
            'Used Count',
            'Total Discount',
            'Valid From',
            'Valid To',
            'Status',
            'Sync Status',
            'Magento Rule ID',
            'Vendor',
            'Created At',
        ]);

        // Data
        foreach ($coupons as $coupon) {
            fputcsv($handle, [
                $coupon->id,
                $coupon->code,
                $coupon->type,
                $coupon->discount_type,
                $coupon->discount_value,
                $coupon->min_order_amount,
                $coupon->max_uses,
                $coupon->used_count,
                $coupon->spent_amount,
                $coupon->starts_at,
                $coupon->expires_at,
                $coupon->is_active ? 'Active' : 'Inactive',
                $coupon->sync_status,
                $coupon->magento_rule_id,
                $coupon->vendor?->company_name,
                $coupon->created_at,
            ]);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $filename,
                'content' => base64_encode($csvContent),
                'mime_type' => 'text/csv',
            ],
        ]);
    }
}
