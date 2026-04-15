<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor\VendorBankAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VendorBankingController extends Controller
{
    /**
     * Get vendor bank accounts
     */
    public function index(Request $request)
    {
        $vendor = $request->user()->vendor;

        $bankAccounts = VendorBankAccount::where('vendor_id', $vendor->getKey())
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $bankAccounts->map(function ($account) {
                return [
                    'id' => $account->id,
                    'account_type' => $account->account_type,
                    'account_holder_name' => $account->account_holder_name,
                    'bank_name' => $account->bank_name,
                    'account_number' => $this->maskAccountNumber($account->account_number),
                    'iban' => $this->maskIban($account->iban),
                    'paypal_email' => $account->paypal_email,
                    'stripe_account_id' => $account->stripe_account_id,
                    'is_primary' => $account->is_primary,
                    'is_verified' => $account->is_verified,
                    'currency_code' => $account->currency_code,
                    'created_at' => $account->created_at,
                ];
            }),
        ]);
    }

    /**
     * Add bank account
     */
    public function store(Request $request)
    {
        $vendor = $request->user()->vendor;

        $validated = $request->validate([
            'account_type' => ['required', Rule::in(['bank', 'paypal', 'stripe'])],
            'account_holder_name' => 'required_if:account_type,bank|string|max:255',
            'bank_name' => 'required_if:account_type,bank|string|max:255',
            'account_number' => 'required_if:account_type,bank|string|max:50',
            'iban' => 'nullable|string|max:34',
            'bic_swift' => 'nullable|string|max:11',
            'paypal_email' => 'required_if:account_type,paypal|email|max:255',
            'stripe_account_id' => 'required_if:account_type,stripe|string|max:255',
            'currency_code' => 'required|string|size:3',
            'is_primary' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            // If this is primary, remove primary from other accounts
            if ($validated['is_primary'] ?? false) {
                VendorBankAccount::where('vendor_id', $vendor->getKey())->update(['is_primary' => false]);
            }

            // Create bank account
            $bankAccount = VendorBankAccount::create([
                'vendor_id' => $vendor->getKey(),
                'account_type' => $validated['account_type'],
                'account_holder_name' => $validated['account_holder_name'] ?? null,
                'bank_name' => $validated['bank_name'] ?? null,
                'account_number' => $validated['account_number'] ?? null,
                'iban' => $validated['iban'] ?? null,
                'bic_swift' => $validated['bic_swift'] ?? null,
                'paypal_email' => $validated['paypal_email'] ?? null,
                'stripe_account_id' => $validated['stripe_account_id'] ?? null,
                'currency_code' => $validated['currency_code'],
                'is_primary' => $validated['is_primary'] ?? false,
                'is_verified' => false,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bank account added successfully',
                'data' => [
                    'id' => $bankAccount->id,
                    'account_type' => $bankAccount->account_type,
                    'is_verified' => $bankAccount->is_verified,
                    'verification_status' => 'pending',
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to add bank account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update bank account
     */
    public function update(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $bankAccount = VendorBankAccount::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        $validated = $request->validate([
            'account_holder_name' => 'sometimes|string|max:255',
            'bank_name' => 'sometimes|string|max:255',
            'account_number' => 'sometimes|string|max:50',
            'iban' => 'nullable|string|max:34',
            'bic_swift' => 'nullable|string|max:11',
            'paypal_email' => 'sometimes|email|max:255',
            'is_primary' => 'boolean',
        ]);

        DB::beginTransaction();

        try {
            // If setting as primary, remove primary from others
            if ($validated['is_primary'] ?? false) {
                VendorBankAccount::where('vendor_id', $vendor->getKey())
                    ->where('id', '!=', $id)
                    ->update(['is_primary' => false]);
            }

            $bankAccount->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bank account updated successfully',
                'data' => $bankAccount,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update bank account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete bank account
     */
    public function destroy(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $bankAccount = VendorBankAccount::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        if ($bankAccount->is_primary) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete primary bank account. Please set another account as primary first.',
            ], 422);
        }

        $bankAccount->delete();

        return response()->json([
            'success' => true,
            'message' => 'Bank account deleted successfully',
        ]);
    }

    /**
     * Set primary bank account
     */
    public function setPrimary(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        DB::beginTransaction();

        try {
            // Remove primary from all accounts
            VendorBankAccount::where('vendor_id', $vendor->getKey())->update(['is_primary' => false]);

            // Set new primary
            $bankAccount = VendorBankAccount::where('vendor_id', $vendor->getKey())
                ->findOrFail($id);
            $bankAccount->is_primary = true;
            $bankAccount->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Primary bank account updated successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to update primary bank account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify bank account (submit for verification)
     */
    public function verify(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $bankAccount = VendorBankAccount::where('vendor_id', $vendor->getKey())
            ->findOrFail($id);

        // Submit for verification (in production, this would call a verification service)
        // For now, just mark as pending verification

        return response()->json([
            'success' => true,
            'message' => 'Bank account submitted for verification',
            'data' => [
                'verification_status' => 'pending',
                'estimated_days' => 2,
            ],
        ]);
    }

    /**
     * Get Stripe Connect onboarding link
     */
    public function getStripeOnboardingLink(Request $request)
    {
        $vendor = $request->user()->vendor;

        // Check if Stripe account already exists
        $stripeAccount = VendorBankAccount::where('vendor_id', $vendor->getKey())
            ->where('account_type', 'stripe')
            ->first();

        if ($stripeAccount && $stripeAccount->stripe_account_id) {
            // Return account link for existing account
            // This would call Stripe API to get account link
            return response()->json([
                'success' => true,
                'data' => [
                    'url' => 'https://connect.stripe.com/express/'.$stripeAccount->stripe_account_id,
                    'account_id' => $stripeAccount->stripe_account_id,
                ],
            ]);
        }

        // Create new Stripe Connect account
        // This would call Stripe API to create account and get onboarding link
        // For now, return mock response
        return response()->json([
            'success' => true,
            'data' => [
                'url' => 'https://connect.stripe.com/express/onboarding',
                'account_id' => 'acct_mock_'.$vendor->getKey(),
            ],
        ]);
    }

    /**
     * Mask account number for display
     */
    private function maskAccountNumber($accountNumber)
    {
        if (! $accountNumber) {
            return null;
        }
        $length = strlen($accountNumber);
        if ($length <= 4) {
            return $accountNumber;
        }

        return str_repeat('*', $length - 4).substr($accountNumber, -4);
    }

    /**
     * Mask IBAN for display
     */
    private function maskIban($iban)
    {
        if (! $iban) {
            return null;
        }
        $length = strlen($iban);
        if ($length <= 8) {
            return $iban;
        }

        return substr($iban, 0, 4).str_repeat('*', $length - 8).substr($iban, -4);
    }
}

