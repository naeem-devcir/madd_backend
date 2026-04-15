<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\SocialLoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\SocialAuthService;
use App\Services\Auth\TokenService;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;

class SocialLoginController extends Controller
{
    protected $socialAuthService;

    protected $tokenService;

    public function __construct(SocialAuthService $socialAuthService, TokenService $tokenService)
    {
        $this->socialAuthService = $socialAuthService;
        $this->tokenService = $tokenService;
    }

    /**
     * Handle social login callback
     */
    public function handle(SocialLoginRequest $request, string $provider)
    {
        $validated = $request->validated();

        DB::beginTransaction();

        try {
            // Verify provider token
            $socialUser = $this->socialAuthService->verifyToken($provider, $validated['access_token']);

            // Find or create user
            $user = $this->socialAuthService->findOrCreateUser($provider, $socialUser, $validated);

            // Generate tokens
            $tokens = $this->tokenService->generateTokens($user, $request->device_name ?? 'social_login');

            DB::commit();

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
     * Get redirect URL for social login
     */
    public function redirect(string $provider)
    {
        $redirectUrl = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();

        return response()->json([
            'success' => true,
            'data' => [
                'redirect_url' => $redirectUrl,
            ],
        ]);
    }
}

