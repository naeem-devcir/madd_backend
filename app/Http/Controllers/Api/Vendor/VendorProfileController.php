<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\UpdateProfileRequest;
use App\Http\Resources\VendorResource;
use App\Models\Vendor\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorProfileController extends Controller
{
    /**
     * Get vendor profile
     */
    public function show(Request $request)
    {
        $vendor = $request->user()->vendor()->with(['user', 'plan', 'bankAccounts'])->first();

        return response()->json([
            'success' => true,
            'data' => new VendorResource($vendor),
        ]);
    }

    /**
     * Update vendor profile
     */
    public function update(UpdateProfileRequest $request)
    {
        $vendor = $request->user()->vendor;

        $validated = $request->validated();

        DB::beginTransaction();

        try {
            // Update vendor
            $vendor->update([
                'company_name' => $validated['company_name'] ?? $vendor->company_name,
                'legal_name' => $validated['legal_name'] ?? $vendor->legal_name,
                'trading_name' => $validated['trading_name'] ?? $vendor->trading_name,
                'vat_number' => $validated['vat_number'] ?? $vendor->vat_number,
                'phone' => $validated['phone'] ?? $vendor->phone,
                'website' => $validated['website'] ?? $vendor->website,
                'address_line1' => $validated['address_line1'] ?? $vendor->address_line1,
                'address_line2' => $validated['address_line2'] ?? $vendor->address_line2,
                'city' => $validated['city'] ?? $vendor->city,
                'postal_code' => $validated['postal_code'] ?? $vendor->postal_code,
                'description' => $validated['description'] ?? $vendor->description,
                'timezone' => $validated['timezone'] ?? $vendor->timezone,
            ]);

            // Update user
            $user = $vendor->user;
            $user->update([
                'first_name' => $validated['first_name'] ?? $user->first_name,
                'last_name' => $validated['last_name'] ?? $user->last_name,
                'phone' => $validated['phone'] ?? $user->phone,
            ]);

            // Upload logo if provided
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('vendor-logos', 's3');
                $vendor->logo_url = $logoPath;
                $vendor->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => new VendorResource($vendor->fresh(['user', 'plan'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get onboarding status
     */
    public function onboardingStatus(Request $request)
    {
        $vendor = $request->user()->vendor;

        $steps = [
            1 => [
                'name' => 'Company Information',
                'completed' => ! empty($vendor->company_name) && ! empty($vendor->address_line1),
                'required' => true,
            ],
            2 => [
                'name' => 'Bank Account',
                'completed' => $vendor->bankAccounts()->where('is_verified', true)->exists(),
                'required' => true,
            ],
            3 => [
                'name' => 'KYC Verification',
                'completed' => $vendor->kyc_status === 'verified',
                'required' => true,
            ],
            4 => [
                'name' => 'Store Setup',
                'completed' => $vendor->stores()->exists(),
                'required' => true,
            ],
            5 => [
                'name' => 'First Product',
                'completed' => $vendor->products()->exists(),
                'required' => false,
            ],
        ];

        $currentStep = $vendor->onboarding_step;
        $isComplete = $vendor->status === 'active';

        return response()->json([
            'success' => true,
            'data' => [
                'current_step' => $currentStep,
                'is_complete' => $isComplete,
                'steps' => $steps,
                'next_step' => $this->getNextStep($currentStep, $steps),
            ],
        ]);
    }

    /**
     * Update onboarding step
     */
    public function updateOnboardingStep(Request $request)
    {

        Log::info($request->all());

        $request->validate([
            'step' => 'required|integer|min:1|max:5',
        ]);

        $vendor = $request->user()->vendor;
        $vendor->onboarding_step = $request->step;
        $vendor->save();

        return response()->json([
            'success' => true,
            'message' => 'Onboarding step updated',
            'data' => [
                'current_step' => $vendor->onboarding_step,
            ],
        ]);
    }

    /**
     * Get next incomplete step
     */
    private function getNextStep($currentStep, $steps)
    {
        for ($i = $currentStep; $i <= count($steps); $i++) {
            if (! $steps[$i]['completed'] && $steps[$i]['required']) {
                return $i;
            }
        }

        return null;
    }
}

