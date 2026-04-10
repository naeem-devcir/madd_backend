<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorPlan;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthService
{
    public function verifyToken(string $provider, string $accessToken): SocialiteUser
    {
        return Socialite::driver($provider)
            ->stateless()
            ->userFromToken($accessToken);
    }

    public function findOrCreateUser(string $provider, SocialiteUser $socialUser, array $context = []): User
    {
        $providerId = (string) $socialUser->getId();
        $email = strtolower(trim($socialUser->getEmail() ?: ($context['provider_email'] ?? '')));

        $socialAccount = SocialAccount::with('user')
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($socialAccount?->user) {
            $this->updateSocialAccount($socialAccount, $socialUser, $email);

            return $socialAccount->user;
        }

        $user = null;

        if ($email !== '') {
            $user = User::where('email', $email)->first();
        }

        if (!$user) {
            $user = $this->createUserFromSocialProfile($email, $context, $socialUser);
        } else {
            $this->syncUserProfile($user, $context, $socialUser);
        }

        $account = SocialAccount::firstOrNew([
            'provider' => $provider,
            'provider_id' => $providerId,
        ]);

        $account->fill([
            'user_id' => $user->uuid,
            'provider_email' => $email ?: null,
            'access_token' => $socialUser->token,
            'refresh_token' => $socialUser->refreshToken,
            'expires_at' => $socialUser->expiresIn ? now()->addSeconds((int) $socialUser->expiresIn) : null,
        ]);
        $account->save();

        return $user->fresh();
    }

    private function createUserFromSocialProfile(string $email, array $context, SocialiteUser $socialUser): User
    {
        $names = $this->extractNames($socialUser, $context);
        $role = $context['force_role'] ?? $context['user_type'] ?? 'customer';

        $user = User::create([
            'email' => $email !== '' ? $email : strtolower(sprintf('%s_%s@example.com', $role, Str::uuid())),
            'first_name' => $names['first_name'],
            'last_name' => $names['last_name'],
            'avatar_url' => $context['avatar'] ?? $socialUser->getAvatar(),
            'user_type' => $role,
            'country_code' => strtoupper($context['country_code'] ?? 'PK'),
            'locale' => $context['locale'] ?? 'en',
            'status' => $role === 'vendor' ? 'pending' : 'active',
            'email_verified_at' => now(),
            'gdpr_consent_at' => now(),
        ]);

        $user->assignRole($role);

        if ($role === 'vendor') {
            $defaultPlan = VendorPlan::where('is_default', true)->first();

            Vendor::firstOrCreate(
                ['user_id' => $user->uuid],
                [
                    'company_name' => $context['company_name'] ?? $user->full_name,
                    'company_slug' => $this->generateCompanySlug($context['company_name'] ?? $user->full_name),
                    'legal_name' => $context['company_name'] ?? $user->full_name,
                    'vat_number' => $context['vat_number'] ?? null,
                    'country_code' => strtoupper($context['country_code'] ?? 'PK'),
                    'address_line1' => $context['address_line1'] ?? '',
                    'city' => $context['city'] ?? '',
                    'postal_code' => $context['postal_code'] ?? '',
                    'contact_email' => $user->email,
                    'plan_id' => $defaultPlan?->id,
                    'status' => 'pending',
                    'kyc_status' => 'pending',
                    'onboarding_step' => 1,
                ]
            );
        }

        return $user;
    }

    private function syncUserProfile(User $user, array $context, SocialiteUser $socialUser): void
    {
        $names = $this->extractNames($socialUser, $context);

        $updates = array_filter([
            'first_name' => $user->first_name ?: $names['first_name'],
            'last_name' => $user->last_name ?: $names['last_name'],
            'avatar_url' => $user->getRawOriginal('avatar_url') ?: ($context['avatar'] ?? $socialUser->getAvatar()),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ], fn ($value) => !is_null($value) && $value !== '');

        if ($updates !== []) {
            $user->update($updates);
        }
    }

    private function updateSocialAccount(SocialAccount $socialAccount, SocialiteUser $socialUser, string $email): void
    {
        $socialAccount->fill([
            'provider_email' => $email !== '' ? $email : $socialAccount->provider_email,
            'access_token' => $socialUser->token,
            'refresh_token' => $socialUser->refreshToken,
            'expires_at' => $socialUser->expiresIn ? now()->addSeconds((int) $socialUser->expiresIn) : null,
        ]);
        $socialAccount->save();
    }

    private function extractNames(SocialiteUser $socialUser, array $context): array
    {
        $name = trim((string) ($socialUser->getName() ?? ''));
        $firstName = trim((string) ($context['first_name'] ?? Arr::get($socialUser->user, 'given_name', '')));
        $lastName = trim((string) ($context['last_name'] ?? Arr::get($socialUser->user, 'family_name', '')));

        if (($firstName === '' || $lastName === '') && $name !== '') {
            $parts = preg_split('/\s+/', $name, 2);
            $firstName = $firstName !== '' ? $firstName : ($parts[0] ?? 'Social');
            $lastName = $lastName !== '' ? $lastName : ($parts[1] ?? 'User');
        }

        return [
            'first_name' => $firstName !== '' ? $firstName : 'Social',
            'last_name' => $lastName !== '' ? $lastName : 'User',
        ];
    }

    private function generateCompanySlug(string $name): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug !== '' ? $slug : 'vendor';
        $slug = $originalSlug;
        $counter = 1;

        while (Vendor::where('company_slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
