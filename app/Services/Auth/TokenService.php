<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class TokenService
{
    /**
     * Generate access and refresh tokens for user
     */
    public function generateTokens(User $user, string $deviceName = 'web'): array
    {
        // Generate access token
        $accessToken = $user->createToken($deviceName, ['*'], now()->addMinutes(config('sanctum.expiration', 1440)));

        // Generate refresh token (store in database)
        $refreshToken = $this->generateRefreshToken();

        // Store session
        UserSession::create([
            'user_id' => $user->uuid,
            'token_jti' => $accessToken->accessToken->id,
            'refresh_token' => $refreshToken['hash'],
            'refresh_token_jti' => $refreshToken['jti'],
            'device_name' => $deviceName,
            'device_type' => $this->getDeviceType($deviceName),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => now()->addDays(30),
        ]);

        return [
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken['token'],
            'expires_in' => config('sanctum.expiration', 1440) * 60,
        ];
    }

    /**
     * Refresh access token using refresh token
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        $session = UserSession::where('refresh_token', hash('sha256', $refreshToken))
            ->where('expires_at', '>', now())
            ->where('is_revoked', false)
            ->first();

        if (!$session) {
            return null;
        }

        $user = $session->user;

        // Revoke old access token
        PersonalAccessToken::where('id', $session->token_jti)->delete();

        // Generate new tokens
        $newTokens = $this->generateTokens($user, $session->device_name);

        // Delete old session
        $session->delete();

        return $newTokens;
    }

    /**
     * Revoke refresh token
     */
    public function revokeRefreshToken(string $refreshToken): void
    {
        UserSession::where('refresh_token', hash('sha256', $refreshToken))->delete();
    }

    /**
     * Generate refresh token
     */
    private function generateRefreshToken(): array
    {
        $token = Str::random(64);
        $jti = Str::uuid()->toString();

        return [
            'token' => $token,
            'jti' => $jti,
            'hash' => hash('sha256', $token),
        ];
    }

    /**
     * Get Magento customer token
     */
    public function getMagentoToken(User $user): ?string
    {
        if (!$user->magento_customer_id) {
            return null;
        }

        // Check cache for existing token
        $cacheKey = "magento_token:{$user->uuid}";
        $token = Cache::get($cacheKey);

        if ($token) {
            return $token;
        }

        // Generate new token via Magento API
        try {
            $response = \Http::post(config('services.magento.base_url') . '/rest/V1/integration/customer/token', [
                'username' => $user->email,
                'password' => request()->password, // This is problematic - better to have stored token
            ]);

            if ($response->successful()) {
                $token = $response->json();
                Cache::put($cacheKey, $token, now()->addHours(12));
                return $token;
            }
        } catch (\Exception $e) {
            \Log::error('Failed to get Magento token', ['user_id' => $user->uuid, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get device type from device name
     */
    private function getDeviceType(string $deviceName): string
    {
        $deviceName = strtolower($deviceName);
        
        if (str_contains($deviceName, 'ios')) {
            return 'ios';
        }
        if (str_contains($deviceName, 'android')) {
            return 'android';
        }
        if (str_contains($deviceName, 'mobile')) {
            return 'mobile';
        }
        
        return 'web';
    }

    /**
     * Validate access token
     */
    public function validateToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);
        
        if (!$accessToken || $accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }
        
        return $accessToken->tokenable;
    }

    /**
     * Revoke all user tokens
     */
    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
        UserSession::where('user_id', $user->uuid)->delete();
    }
}
