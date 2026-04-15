<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorPlan;
use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VendorAuthController extends Controller
{
    protected $tokenService;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * Vendor Login
     */
    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user is a vendor
        if (! $user->hasRole('vendor') && $user->user_type !== 'vendor') {
            return response()->json([
                'success' => false,
                'message' => 'This account is not registered as a vendor.',
            ], 403);
        }

        // Check vendor status
        $vendor = $user->vendor;
        if ($vendor->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your vendor account is '.$vendor->status.'. Please contact support.',
            ], 403);
        }

        // Check if email is verified
        if (! $user->is_email_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email address first.',
                'requires_verification' => true,
            ], 403);
        }

        // Reset login attempts
        $user->resetLoginAttempts();

        // Update last login
        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        // Generate tokens
        $tokens = $this->tokenService->generateTokens($user, $request->device_name ?? 'vendor_portal');

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($user->load('vendor')),
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_type' => 'Bearer',
                'expires_in' => $tokens['expires_in'],
                'vendor_status' => $vendor->status,
                'onboarding_step' => $vendor->onboarding_step,
            ],
        ]);
    }

    /**
     * Vendor Registration
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        // Ensure user type is vendor
        $validated['user_type'] = 'vendor';

        DB::beginTransaction();

        try {
            // Create user
            $user = User::create([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'] ?? null,
                'user_type' => 'vendor',
                'country_code' => $validated['country_code'],
                'locale' => $validated['locale'] ?? 'en',
                'status' => 'active',
                'email_verified_at' => now(), // Auto-verify for demo, remove in production
                'gdpr_consent_at' => now(),
                'marketing_opt_in' => $validated['marketing_opt_in'] ?? false,
            ]);

            // Assign vendor role
            $user->assignRole('vendor');

            // Get default plan
            $defaultPlan = VendorPlan::where('is_default', true)->first();

            // Create vendor record
            $vendor = Vendor::create([
                'user_id' => $user->id,
                'company_name' => $validated['company_name'],
                'company_slug' => $this->generateCompanySlug($validated['company_name']),
                'legal_name' => $validated['company_name'],
                'vat_number' => $validated['vat_number'] ?? null,
                'country_code' => $validated['country_code'],
                'address_line1' => $validated['address_line1'] ?? '',
                'city' => $validated['city'] ?? '',
                'postal_code' => $validated['postal_code'] ?? '',
                'contact_email' => $validated['email'],
                'plan_id' => $defaultPlan?->id,
                'status' => 'pending',
                'kyc_status' => 'pending',
                'onboarding_step' => 1,
            ]);

            DB::commit();

            // Send welcome email
            // \App\Jobs\Notification\SendVendorWelcomeEmail::dispatch($vendor);

            // Generate tokens
            $tokens = $this->tokenService->generateTokens($user, 'vendor_registration');

            return response()->json([
                'success' => true,
                'message' => 'Vendor registration successful. Your application is pending approval.',
                'data' => [
                    'user' => new UserResource($user),
                    'vendor' => [
                        'id' => $vendor->id,
                        'status' => $vendor->status,
                        'onboarding_step' => $vendor->onboarding_step,
                    ],
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'Bearer',
                    'expires_in' => $tokens['expires_in'],
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vendor Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Refresh Token
     */
    public function refresh(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required|string',
        ]);

        $tokens = $this->tokenService->refreshTokens($request->refresh_token);

        if (! $tokens) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_type' => 'Bearer',
                'expires_in' => $tokens['expires_in'],
            ],
        ]);
    }

    /**
     * Generate unique company slug
     */
    private function generateCompanySlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (Vendor::where('company_slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}

