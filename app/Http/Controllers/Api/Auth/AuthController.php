<?php

namespace App\Http\Controllers\Api\Auth;

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
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected $tokenService;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * Register a new user
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        DB::beginTransaction();

        try {
            // Create user
            $user = User::create([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'phone' => $validated['phone'] ?? null,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'user_type' => $validated['user_type'] ?? 'customer',
                'country_code' => $validated['country_code'],
                'locale' => $validated['locale'] ?? 'en',
                'status' => 'pending',
                'gdpr_consent_at' => now(),
                'marketing_opt_in' => $validated['marketing_opt_in'] ?? false,
            ]);

            // Assign default role
            $role = $validated['user_type'] ?? 'customer';

            $user->assignRole($role);
           

            // If vendor registration, create vendor record
            if ($role === 'vendor') {
                $defaultPlan = VendorPlan::where('is_default', true)->first();

                Vendor::create([
                    'user_id' => $user->uuid,
                    'company_name' => $validated['company_name'] ?? $validated['first_name'] . ' ' . $validated['last_name'],
                    'company_slug' => $this->generateCompanySlug($validated['company_name'] ?? $user->full_name),
                    'country_code' => $validated['country_code'],
                    'address_line1' => $validated['address_line1'] ?? '',
                    'city' => $validated['city'] ?? '',
                    'postal_code' => $validated['postal_code'] ?? '',
                    'plan_id' => $defaultPlan?->id,
                    'status' => 'pending',
                    'kyc_status' => 'pending',
                ]);
            }

            DB::commit();

            // Send email verification
            $user->sendEmailVerificationNotification();

            // Generate tokens
            $tokens = $this->tokenService->generateTokens($user);

            return response()->json([
                'success' => true,
                'message' => 'Registration successful. Please verify your email.',
                'data' => [
                    'user' => new UserResource($user),
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'Bearer',
                    'expires_in' => $tokens['expires_in'],
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login user
     */
    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        // Find user
        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user is locked
        if ($user->is_locked) {
            throw ValidationException::withMessages([
                'email' => ['Your account is locked. Please try again later.'],
            ]);
        }

        // Check if email is verified
        // if (!$user->is_email_verified && !$validated['bypass_verification'] ?? false) {
        if (!$user->is_email_verified && !($request->input('bypass_verification', false))) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email address first.',
                'requires_verification' => true,
            ], 403);
        }

        // Check account status
        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is ' . $user->status . '. Please contact support.',
            ], 403);
        }

        // Reset login attempts on successful login
        $user->resetLoginAttempts();

        // Update last login info
        $user->last_login_at = now();
        $user->last_login_ip = $request->ip();
        $user->save();

        // Generate tokens
        $tokens = $this->tokenService->generateTokens($user, $request->device_name ?? 'web');

        // Get Magento token if needed
        $magentoToken = null;
        if ($user->magento_customer_id) {
            $magentoToken = $this->tokenService->getMagentoToken($user);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($user->load(['vendor', 'mlmAgent'])),
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'magento_token' => $magentoToken,
                'token_type' => 'Bearer',
                'expires_in' => $tokens['expires_in'],
                'permissions' => $user->getPermissionArray(),
            ]
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        // Revoke current access token
        $request->user()->currentAccessToken()->delete();

        // Also revoke refresh token if using custom refresh tokens
        if ($request->has('refresh_token')) {
            $this->tokenService->revokeRefreshToken($request->refresh_token);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Refresh access token
     */
    public function refresh(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required|string',
        ]);

        $tokens = $this->tokenService->refreshTokens($request->refresh_token);

        if (!$tokens) {
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
            ]
        ]);
    }

    /**
     * Get authenticated user profile
     */
    public function profile(Request $request)
    {
        $user = $request->user()->load(['vendor', 'mlmAgent', 'roles', 'permissions']);

        return response()->json([
            'success' => true,
            'data' => new UserResource($user)
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'sometimes|string|max:100',
            'phone' => 'sometimes|string|max:20|unique:users,phone,' . $user->uuid,
            'avatar_url' => 'nullable|url|max:500',
            'locale' => 'sometimes|string|size:2',
            'timezone' => 'sometimes|string|max:50',
            'marketing_opt_in' => 'sometimes|boolean',
        ]);

        $user->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => new UserResource($user->fresh())
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect',
            ], 422);
        }

        $user->password = $request->new_password;
        $user->save();

        // Revoke all other tokens except current
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
    }

    /**
     * Delete user account (GDPR Right to Erasure)
     */
    public function deleteAccount(Request $request)
    {
        $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|accepted',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password is incorrect',
            ], 422);
        }

        // Anonymize user data instead of hard delete for GDPR compliance
        $user->update([
            'email' => 'deleted_' . $user->uuid . '@example.com',
            'phone' => null,
            'first_name' => 'Deleted',
            'last_name' => 'User',
            'avatar_url' => null,
            'password' => null,
            'status' => 'deleted',
            'email_verified_at' => null,
            'magento_customer_id' => null,
            'two_factor_secret' => null,
            'gdpr_consent_at' => null,
            'preferences' => null,
            'metadata' => array_merge($user->metadata ?? [], ['deleted_at' => now()->toIso8601String()]),
        ]);

        // Revoke all tokens
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account deleted successfully'
        ]);
    }

    /**
     * Generate company slug from name
     */
    private function generateCompanySlug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $originalSlug = $slug;
        $counter = 1;

        while (Vendor::where('company_slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
