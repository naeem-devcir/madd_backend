<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Config\Coupon;
use App\Services\Promotion\CouponService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminCouponController extends Controller
{
    protected $couponService;

    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    /**
     * Get all coupons with filters
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
                $q->where('code', 'like', '%'.$request->search.'%')
                    ->orWhere('description', 'like', '%'.$request->search.'%');
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
     * Create a new coupon
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
            'usage_limit_per_transaction' => 'integer|min:1|default:1',
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

        DB::beginTransaction();

        try {
            $coupon = Coupon::create($validated);

            // Sync to Magento if platform coupon
            if ($coupon->type === 'platform') {
                $this->couponService->syncToMagento($coupon);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Coupon created successfully',
                'data' => $coupon->load('vendor'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to create coupon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single coupon
     */
    public function show($id)
    {
        $coupon = Coupon::with(['vendor', 'orders'])->findOrFail($id);

        // Get usage statistics
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
    }

    /**
     * Update coupon
     */
    public function update(Request $request, $id)
    {
        $coupon = Coupon::findOrFail($id);

        $validated = $request->validate([
            'code' => 'sometimes|string|max:50|unique:coupons,code,'.$coupon->id,
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

        DB::beginTransaction();

        try {
            $coupon->update($validated);

            // Resync to Magento
            if ($coupon->type === 'platform') {
                $this->couponService->syncToMagento($coupon);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Coupon updated successfully',
                'data' => $coupon->fresh(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update coupon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete coupon
     */
    public function destroy($id)
    {
        $coupon = Coupon::findOrFail($id);

        // Check if coupon has been used
        if ($coupon->used_count > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete coupon that has been used. Consider deactivating it instead.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Remove from Magento if synced
            if ($coupon->magento_rule_id && $coupon->type === 'platform') {
                $this->couponService->removeFromMagento($coupon);
            }

            $coupon->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Coupon deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete coupon',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toggle coupon status
     */
    public function toggleStatus($id)
    {
        $coupon = Coupon::findOrFail($id);

        $coupon->is_active = ! $coupon->is_active;
        $coupon->save();

        // Sync status to Magento
        if ($coupon->type === 'platform' && $coupon->magento_rule_id) {
            $this->couponService->syncStatusToMagento($coupon);
        }

        return response()->json([
            'success' => true,
            'message' => $coupon->is_active ? 'Coupon activated' : 'Coupon deactivated',
            'data' => [
                'is_active' => $coupon->is_active,
            ],
        ]);
    }

    /**
     * Duplicate coupon
     */
    public function duplicate($id)
    {
        $originalCoupon = Coupon::findOrFail($id);

        $newCoupon = $originalCoupon->replicate();
        $newCoupon->code = $originalCoupon->code.'_copy_'.time();
        $newCoupon->used_count = 0;
        $newCoupon->spent_amount = 0;
        $newCoupon->magento_rule_id = null;
        $newCoupon->magento_coupon_id = null;
        $newCoupon->sync_status = 'pending';
        $newCoupon->is_active = false;
        $newCoupon->save();

        return response()->json([
            'success' => true,
            'message' => 'Coupon duplicated successfully',
            'data' => $newCoupon,
        ], 201);
    }

    /**
     * Get coupon statistics
     */
    public function statistics()
    {
        $stats = [
            'total' => Coupon::count(),
            'active' => Coupon::where('is_active', true)->count(),
            'inactive' => Coupon::where('is_active', false)->count(),
            'platform_coupons' => Coupon::where('type', 'platform')->count(),
            'vendor_coupons' => Coupon::where('type', 'vendor')->count(),
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
     * Export coupons to CSV
     */
    public function export(Request $request)
    {
        $query = Coupon::with(['vendor']);

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $coupons = $query->get();

        $filename = 'coupons_export_'.date('Y-m-d_His').'.csv';
        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'ID', 'Code', 'Type', 'Discount Type', 'Discount Value',
            'Min Order', 'Max Uses', 'Used Count', 'Total Discount',
            'Valid From', 'Valid To', 'Status', 'Vendor', 'Created At',
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

    /**
     * Placeholder until Magento coupon sync is implemented.
     */
    public function syncToMagento($id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Coupon sync to Magento is not implemented yet.',
            'coupon_id' => $id,
        ], 501);
    }
}

