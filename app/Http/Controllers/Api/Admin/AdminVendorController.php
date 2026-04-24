<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreVendorRequest;
use App\Http\Resources\VendorResource;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorPlan;
use App\Services\Vendor\VendorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;



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
                $q->where('company_name', 'like', '%' . $request->search . '%')
                    ->orWhere('company_slug', 'like', '%' . $request->search . '%')
                    ->orWhere('vat_number', 'like', '%' . $request->search . '%')
                    ->orWhereHas('user', function ($sub) use ($request) {
                        $sub->where('email', 'like', '%' . $request->search . '%');
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
     * Create a new vendor (admin adds vendor manually)
     */
    public function store(StoreVendorRequest $request)
    {
        DB::beginTransaction();

        try {
            // 1. Create the user account
            $user = User::create([
                'uuid' => (string) Str::uuid(),
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'phone' => $request->phone,
                'user_type' => 'vendor',
                'status' => 'active',
                'country_code' => $request->country_code,
                'is_super_admin' => false,
                'is_email_verified' => true, // Admin-created, assume verified
                'is_phone_verified' => false,
                'is_kyc_verified' => $request->kyc_status === 'verified',
                'kyc_status' => $request->kyc_status ?? 'pending',
                'timezone' => $request->timezone ?? 'UTC',
                'created_by' => auth()->id(),
            ]);

            // 2. Handle plan dates if plan is selected
            $planStartsAt = null;
            $planEndsAt = null;
            $planDurationMonths = $request->plan_duration_months ?? 12;

            if ($request->plan_id) {
                $planStartsAt = now();
                $planEndsAt = now()->addMonths($planDurationMonths);
            }

            // 3. Generate a unique slug if not provided
            $slug = $request->company_slug;
            if (Vendor::where('company_slug', $slug)->exists()) {
                $slug = $slug . '-' . Str::random(4);
            }

            // 4. Create the vendor record
            $vendor = Vendor::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'company_name' => $request->company_name,
                'company_slug' => $slug,
                'legal_name' => $request->legal_name,
                'trading_name' => $request->trading_name,
                'vat_number' => $request->vat_number,
                'registration_number' => $request->registration_number,
                'contact_email' => $request->contact_email ?? $request->email,
                'phone' => $request->phone,
                'website' => $request->website,
                'country_code' => $request->country_code,
                'address_line1' => $request->address_line1,
                'address_line2' => $request->address_line2,
                'city' => $request->city,
                'postal_code' => $request->postal_code,
                'logo_url' => $request->logo_url,
                'banner_url' => $request->banner_url,
                'description' => $request->description,
                'plan_id' => $request->plan_id,
                'plan_starts_at' => $planStartsAt,
                'plan_ends_at' => $planEndsAt,
                'plan_duration_months' => $planDurationMonths,
                'commission_rate' => $request->commission_rate,
                'commission_type' => $request->commission_type ?? 'percentage',
                'status' => $request->status ?? 'pending',
                'kyc_status' => $request->kyc_status ?? 'pending',
                'onboarding_step' => $request->status === 'active' ? 5 : 1,
                'timezone' => $request->timezone ?? 'UTC',
                'metadata' => $request->metadata,
                'approved_by' => $request->status === 'active' ? auth()->id() : null,
                'approved_at' => $request->status === 'active' ? now() : null,
            ]);

            // 5. Send welcome email to vendor (optional)
            // event(new VendorAccountCreated($user, $vendor, $request->password));

            DB::commit();

            // Load relationships for response
            $vendor->load(['user', 'plan']);

            return response()->json([
                'success' => true,
                'message' => 'Vendor created successfully',
                'data' => new VendorResource($vendor),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create vendor', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['password', 'password_confirmation']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create vendor: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get single vendor
     */
    public function show($id)
    {
        // $vendor = Vendor::with(['user', 'plan', 'stores', 'bankAccounts', 'products', 'orders', 'settlements'])
        //     ->findOrFail($id);
        $vendor = Vendor::with(['user', 'plan', 'stores', 'bankAccounts', 'products', 'orders', 'settlements'])
            ->where('uuid', $id)
            ->firstOrFail();

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
        // $vendor = Vendor::findOrFail($id);
        $vendor = Vendor::where('uuid', $id)->firstOrFail();

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

        // $vendor = Vendor::findOrFail($id);
        $vendor = Vendor::where('uuid', $id)->firstOrFail();

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
        // $vendor = Vendor::findOrFail($id);
        $vendor = Vendor::where('uuid', $id)->firstOrFail();

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

        // $vendor = Vendor::findOrFail($id);
        $vendor = Vendor::where('uuid', $id)->firstOrFail();

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
            // 'revenue' => [
            //     'total_commission' => Vendor::sum('total_commission_paid'),
            //     // Fixed: MySQL compatible version
            //     'avg_monthly' => Vendor::where('created_at', '<=', now())
            //         ->where('total_commission_paid', '>', 0)
            //         ->selectRaw('AVG(total_commission_paid / GREATEST(TIMESTAMPDIFF(MONTH, created_at, NOW()), 1)) as avg_monthly')
            //         ->value('avg_monthly') ?? 0,
            // ],
            'revenue' => [
                'total_commission' => Vendor::sum('total_commission_paid'),
                'avg_monthly' => Vendor::where('created_at', '<=', now())
                    ->where('total_commission_paid', '>', 0)
                    ->selectRaw('AVG(total_commission_paid / GREATEST(TIMESTAMPDIFF(MONTH, created_at, NOW()), 1)) as avg_monthly')
                    ->value('avg_monthly') ?? 0,
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
