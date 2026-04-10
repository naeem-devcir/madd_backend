<?php

namespace App\Http\Controllers;

use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
class VendorController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /vendors
    // -------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $query = Vendor::with(['user', 'plan'])
            ->when($request->status,       fn($q) => $q->where('status', $request->status))
            ->when($request->kyc_status,   fn($q) => $q->where('kyc_status', $request->kyc_status))
            ->when($request->country_code, fn($q) => $q->byCountry($request->country_code))
            ->when($request->search, function ($q) use ($request) {
                $q->where(function ($inner) use ($request) {
                    $inner->where('company_name', 'like', "%{$request->search}%")
                          ->orWhere('company_slug', 'like', "%{$request->search}%")
                          ->orWhere('contact_email', 'like', "%{$request->search}%");
                });
            });

        $vendors = $query->orderBy('created_at', 'desc')
                         ->paginate($request->per_page ?? 15);

        return response()->json($vendors);
    }

    // -------------------------------------------------------------------------
    // POST /vendors
    // -------------------------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        
        $validated = $request->validate([
            // user_id is integer (users.id), NOT uuid
            'user_id'             => ['required', 'integer', 'exists:users,id'],
            'company_name'        => ['required', 'string', 'max:255'],
            'company_slug'        => ['nullable', 'string', 'max:255', 'unique:vendors,company_slug', 'alpha_dash'],
            'vat_number'          => ['nullable', 'string', 'max:50'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'country_code'        => ['required', 'string', 'size:2'],
            'address_line1'       => ['required', 'string', 'max:255'],
            'address_line2'       => ['nullable', 'string', 'max:255'],
            'city'                => ['required', 'string', 'max:100'],
            'postal_code'         => ['required', 'string', 'max:20'],
            'plan_id'             => ['nullable', 'integer', 'exists:vendor_plans,id'],
            'plan_starts_at'      => ['nullable', 'date'],
            'plan_ends_at'        => ['nullable', 'date', 'after:plan_starts_at'],
            // mlm_referrer_id is integer (users.id), NOT uuid
            'mlm_referrer_id'     => ['nullable', 'integer', 'exists:users,id'],
            'logo_url'            => ['nullable', 'url', 'max:500'],
            'kyc_status'          => ['nullable', Rule::in(['pending', 'verified', 'rejected'])],
            'commission_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contact_email'       => ['nullable', 'email', 'max:191'],
            'timezone'            => ['nullable', 'timezone'],
        ]);
        // Auto-generate slug if not provided
        $validated['company_slug'] = $validated['company_slug']
            ?? Str::slug($validated['company_name']);

        $vendor = Vendor::create($validated);

        return response()->json($vendor->load(['user', 'plan']), 201);
    }

    // -------------------------------------------------------------------------
    // GET /vendors/{vendor}
    // -------------------------------------------------------------------------

    public function show(Vendor $vendor): JsonResponse
    {
        return response()->json(
            $vendor->load(['user', 'plan', 'mlmReferrer', 'approvedByUser'])
        );
    }

    // -------------------------------------------------------------------------
    // PUT /vendors/{vendor}
    // -------------------------------------------------------------------------

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $validated = $request->validate([
            'company_name'        => ['sometimes', 'string', 'max:255'],
            'company_slug'        => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('vendors', 'company_slug')->ignore($vendor->id)],
            'vat_number'          => ['nullable', 'string', 'max:50'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'country_code'        => ['sometimes', 'string', 'size:2'],
            'address_line1'       => ['sometimes', 'string', 'max:255'],
            'address_line2'       => ['nullable', 'string', 'max:255'],
            'city'                => ['sometimes', 'string', 'max:100'],
            'postal_code'         => ['sometimes', 'string', 'max:20'],
            'plan_id'             => ['nullable', 'integer', 'exists:vendor_plans,id'],
            'plan_starts_at'      => ['nullable', 'date'],
            'plan_ends_at'        => ['nullable', 'date', 'after:plan_starts_at'],
            'status'              => ['sometimes', Rule::in(['pending', 'active', 'suspended', 'terminated'])],
            'onboarding_step'     => ['sometimes', 'integer', 'min:1'],
            'logo_url'            => ['nullable', 'url', 'max:500'],
            'kyc_status'          => ['sometimes', Rule::in(['pending', 'verified', 'rejected'])],
            'commission_override' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'contact_email'       => ['nullable', 'email', 'max:191'],
            'timezone'            => ['nullable', 'timezone'],
        ]);

        $vendor->update($validated);

        return response()->json($vendor->fresh(['user', 'plan']));
    }

    // -------------------------------------------------------------------------
    // DELETE /vendors/{vendor}
    // -------------------------------------------------------------------------

    public function destroy(Vendor $vendor): JsonResponse
    {
        $vendor->delete(); // soft delete

        return response()->json(['message' => 'Vendor deleted successfully.']);
    }

    // -------------------------------------------------------------------------
    // POST /vendors/{vendor}/approve
    // -------------------------------------------------------------------------

    public function approve(Request $request, Vendor $vendor): JsonResponse
    {
        $vendor->update([
            'status'      => 'active',
            'approved_by' => $request->user()->id, // users.id (integer)
            'approved_at' => now(),
        ]);

        return response()->json(['message' => 'Vendor approved.', 'vendor' => $vendor]);
    }

    // -------------------------------------------------------------------------
    // POST /vendors/{vendor}/suspend
    // -------------------------------------------------------------------------

    public function suspend(Vendor $vendor): JsonResponse
    {
        $vendor->update(['status' => 'suspended']);

        return response()->json(['message' => 'Vendor suspended.', 'vendor' => $vendor]);
    }
}