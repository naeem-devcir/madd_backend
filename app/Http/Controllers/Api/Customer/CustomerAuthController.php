<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Requests\Api\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\SocialAuthService;
use App\Services\Auth\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class CustomerAuthController extends Controller
{
    protected $tokenService;

    protected $socialAuthService;

    public function __construct(TokenService $tokenService, SocialAuthService $socialAuthService)
    {
        $this->tokenService = $tokenService;
        $this->socialAuthService = $socialAuthService;
    }

    /**
     * Register a new customer
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        // Force user type to customer
        $validated['user_type'] = 'customer';

        DB::beginTransaction();

        try {
            // Create user
            $user = User::create([
                'email' => $validated['email'],
                'password' => $validated['password'],
                'phone' => $validated['phone'] ?? null,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'user_type' => 'customer',
                'country_code' => $validated['country_code'],
                'locale' => $validated['locale'] ?? 'en',
                'status' => 'pending_verification',
                'gdpr_consent_at' => now(),
                'marketing_opt_in' => $validated['marketing_opt_in'] ?? false,
            ]);

            // Assign customer role
            $user->assignRole('customer');

            DB::commit();

            // Send email verification
            $user->sendEmailVerificationNotification();

            // Generate tokens
            $tokens = $this->tokenService->generateTokens($user, $request->device_name ?? 'web');

            return response()->json([
                'success' => true,
                'message' => 'Registration successful. Please verify your email.',
                'data' => [
                    'user' => new UserResource($user),
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
     * Login customer
     */
    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        // Find user
        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user is a customer
        if (! $user->is_customer && ! $user->hasRole('customer')) {
            return response()->json([
                'success' => false,
                'message' => 'This account is not a customer account. Please use the correct login portal.',
            ], 403);
        }

        // Check if user is locked
        if ($user->is_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Your account is locked due to too many failed attempts. Please try again later.',
                'locked_until' => $user->locked_until->toIso8601String(),
            ], 423);
        }

        // Check if email is verified
        if (! $user->is_email_verified && ! ($validated['bypass_verification'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email address first.',
                'requires_verification' => true,
                'email' => $user->email,
            ], 403);
        }

        // Check account status
        if ($user->status !== 'active') {
            $statusMessages = [
                'pending_verification' => 'Please verify your email address to activate your account.',
                'suspended' => 'Your account has been suspended. Please contact support.',
                'banned' => 'Your account has been banned.',
            ];

            return response()->json([
                'success' => false,
                'message' => $statusMessages[$user->status] ?? 'Your account is not active.',
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

        // Get Magento customer token if available
        $magentoToken = null;
        if ($user->magento_customer_id) {
            $magentoToken = $this->tokenService->getMagentoToken($user);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => new UserResource($user->load(['roles'])),
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'magento_token' => $magentoToken,
                'token_type' => 'Bearer',
                'expires_in' => $tokens['expires_in'],
                'permissions' => $user->getPermissionArray(),
            ],
        ]);
    }

    /**
     * Social login for customers
     */
    public function socialLogin(Request $request, string $provider)
    {
        $request->validate([
            'access_token' => 'required|string',
        ]);

        DB::beginTransaction();

        try {
            // Verify provider token
            $socialUser = $this->socialAuthService->verifyToken($provider, $request->access_token);

            // Find or create user with customer role
            $user = $this->socialAuthService->findOrCreateUser($provider, $socialUser, [
                'user_type' => 'customer',
                'force_role' => 'customer',
            ]);

            DB::commit();

            // Generate tokens
            $tokens = $this->tokenService->generateTokens($user, $request->device_name ?? 'social_login');

            return response()->json([
                'success' => true,
                'message' => 'Social login successful',
                'data' => [
                    'user' => new UserResource($user),
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_type' => 'Bearer',
                    'expires_in' => $tokens['expires_in'],
                    'is_new_user' => $user->wasRecentlyCreated,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Social login failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Logout customer
     */
    public function logout(Request $request)
    {
        // Revoke current access token
        $request->user()->currentAccessToken()->delete();

        // Revoke refresh token if provided
        if ($request->has('refresh_token')) {
            $this->tokenService->revokeRefreshToken($request->refresh_token);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
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
     * Send password reset link
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Only allow password reset for customer accounts
        if (! $user->is_customer) {
            return response()->json([
                'success' => false,
                'message' => 'Password reset not available for this account type.',
            ], 400);
        }

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset link sent to your email',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unable to send reset link',
        ], 400);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->password = $password;
                $user->save();

                // Revoke all tokens
                $user->tokens()->delete();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset successful. Please login with your new password.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Password reset failed. Please try again.',
        ], 400);
    }

    /**
     * Resend email verification
     */
    public function resendVerification(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified',
            ], 400);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Verification email sent',
        ]);
    }

    /**
     * Verify email
     */
    public function verifyEmail(Request $request, $id, $hash)
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification link',
            ], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'message' => 'Email already verified',
            ]);
        }

        $user->markEmailAsVerified();

        // Activate account if it was pending verification
        if ($user->status === 'pending_verification') {
            $user->status = 'active';
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully. You can now login.',
        ]);
    }

    /**
     * Check if email exists
     */
    public function checkEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $exists = User::where('email', $request->email)->exists();

        return response()->json([
            'success' => true,
            'data' => [
                'exists' => $exists,
                'message' => $exists ? 'Email is already registered' : 'Email is available',
            ],
        ]);
    }

    /**
     * Get redirect URL for social login
     */
    public function getSocialRedirect(string $provider)
    {
        $providers = ['google', 'facebook', 'apple'];

        if (! in_array($provider, $providers)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid provider',
            ], 400);
        }

        $redirectUrl = Socialite::driver($provider)
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'success' => true,
            'data' => [
                'redirect_url' => $redirectUrl,
                'provider' => $provider,
            ],
        ]);
    }
}

