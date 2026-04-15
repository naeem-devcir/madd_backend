<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorResource;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorPlan;
use App\Services\Vendor\VendorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminVendorController extends Controller
{
    protected $vendorService;

    public function __construct(VendorService $vendorService)
    {
        $this->vendorService = $vendorService;
    }

    /**
     * Get all vendors with filters
     */
    public function index(Request $request)
    {
        $query = Vendor::with(['user', 'plan', 'stores']);

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('kyc_status')) {
            $query->where('kyc_status', $request->kyc_status);
        }

        if ($request->has('country_code')) {
            $query->where('country_code', $request->country_code);
        }

        if ($request->has('plan_id')) {
            $query->where('plan_id', $request->plan_id);
        }

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('company_name', 'like', '%'.$request->search.'%')
                    ->orWhere('company_slug', 'like', '%'.$request->search.'%')
                    ->orWhere('vat_number', 'like', '%'.$request->search.'%')
                    ->orWhereHas('user', function ($sub) use ($request) {
                        $sub->where('email', 'like', '%'.$request->search.'%');
                    });
            });
        }

        $vendors = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => VendorResource::collection($vendors),
            'meta' => [
                'current_page' => $vendors->currentPage(),
                'last_page' => $vendors->lastPage(),
                'total' => $vendors->total(),
            ],
        ]);
    }

    /**
     * Get single vendor
     */
    public function show($id)
    {
        $vendor = Vendor::with(['user', 'plan', 'stores', 'bankAccounts', 'products', 'orders', 'settlements'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new VendorResource($vendor),
        ]);
    }

    /**
     * Approve vendor application
     */
    public function approve(Request $request, $id)
    {
        $vendor = Vendor::findOrFail($id);

        if ($vendor->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Vendor is not in pending status',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->vendorService->approveVendor($vendor, auth()->user());

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor approved successfully',
                'data' => new VendorResource($vendor->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve vendor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Suspend vendor
     */
    public function suspend(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $vendor = Vendor::findOrFail($id);

        DB::beginTransaction();

        try {
            $vendor->suspend($request->reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor suspended successfully',
                'data' => new VendorResource($vendor->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to suspend vendor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activate suspended vendor
     */
    public function activate($id)
    {
        $vendor = Vendor::findOrFail($id);

        if ($vendor->status !== 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Vendor is not suspended',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $vendor->activate();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor activated successfully',
                'data' => new VendorResource($vendor->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to activate vendor',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update vendor plan
     */
    public function updatePlan(Request $request, $id)
    {
        $request->validate([
            'plan_id' => 'required|exists:vendor_plans,id',
            'duration_months' => 'sometimes|integer|min:1|max:36',
        ]);

        $vendor = Vendor::findOrFail($id);
        $plan = VendorPlan::findOrFail($request->plan_id);

        DB::beginTransaction();

        try {
            $vendor->updatePlan($plan, $request->duration_months ?? 12);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vendor plan updated successfully',
                'data' => new VendorResource($vendor->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update vendor plan',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get pending vendor applications
     */
    public function applications(Request $request)
    {
        $applications = Vendor::with(['user'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => VendorResource::collection($applications),
            'meta' => [
                'total_pending' => Vendor::where('status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * Get vendor statistics
     */
    public function statistics()
    {
        $stats = [
            'total' => Vendor::count(),
            'by_status' => [
                'pending' => Vendor::where('status', 'pending')->count(),
                'active' => Vendor::where('status', 'active')->count(),
                'suspended' => Vendor::where('status', 'suspended')->count(),
                'terminated' => Vendor::where('status', 'terminated')->count(),
            ],
            'by_kyc' => [
                'pending' => Vendor::where('kyc_status', 'pending')->count(),
                'verified' => Vendor::where('kyc_status', 'verified')->count(),
                'rejected' => Vendor::where('kyc_status', 'rejected')->count(),
            ],
            'by_country' => Vendor::select('country_code', DB::raw('count(*) as count'))
                ->groupBy('country_code')
                ->get(),
            'by_plan' => Vendor::select('plan_id', DB::raw('count(*) as count'))
                ->with('plan')
                ->groupBy('plan_id')
                ->get(),
            'revenue' => [
                'total_commission' => Vendor::sum('total_commission_paid'),
                'avg_monthly' => Vendor::avg(DB::raw('total_commission_paid / GREATEST(EXTRACT(MONTH FROM AGE(NOW(), created_at)), 1)')),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Placeholder until KYC verification flow is implemented.
     */
    public function verifyKyc(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Vendor KYC verification is not implemented yet.',
            'vendor_id' => $id,
        ], 501);
    }

    /**
     * Placeholder until KYC rejection flow is implemented.
     */
    public function rejectKyc(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Vendor KYC rejection is not implemented yet.',
            'vendor_id' => $id,
        ], 501);
    }
}

