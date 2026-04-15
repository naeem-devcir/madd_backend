<?php

namespace App\Models;

use App\Models\Financial\Settlement;
use App\Models\Financial\Transaction;
use App\Models\Mlm\MlmAgent;
use App\Models\Notification\Notification;
use App\Models\Order\Order;
use App\Models\Review\Review;
use App\Models\Traits\HasUuid;
use App\Models\Vendor\Vendor;
use App\Models\Vendor\VendorUser;
use DateTimeInterface;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens {
        createToken as sanctumCreateToken;
    }
    use HasFactory, HasRoles, HasUuid, MustVerifyEmail, Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'uuid',
        'email',
        'password',
        'phone',
        'first_name',
        'last_name',
        'avatar_url',
        'user_type',
        'status',
        'email_verified_at',
        'phone_verified_at',
        'magento_customer_id',
        'locale',
        'timezone',
        'country_code',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'last_login_at',
        'last_login_ip',
        'login_attempts',
        'locked_until',
        'gdpr_consent_at',
        'marketing_opt_in',
        'kyc_status',
        'preferences',
        'metadata',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
        'gdpr_consent_at' => 'datetime',
        'preferences' => 'array',
        'metadata' => 'array',
        'marketing_opt_in' => 'boolean',
        'login_attempts' => 'integer',
        'two_factor_recovery_codes' => 'array',
    ];

    // ========== Relationships ==========

    public function vendor()
    {
        return $this->hasOne(Vendor::class, 'user_id', 'id');
    }

    public function vendorUser()
    {
        return $this->hasOne(VendorUser::class, 'user_id', 'id');
    }

    public function mlmAgent()
    {
        return $this->hasOne(MlmAgent::class, 'user_id', 'id');
    }

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class, 'user_id', 'id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id', 'id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'customer_id', 'id');
    }

    public function notifications()
    {
        return $this->morphMany(Notification::class, 'notifiable');
    }

    public function approvedVendors()
    {
        return $this->hasMany(Vendor::class, 'approved_by', 'id');
    }

    public function createdVendors()
    {
        return $this->hasMany(Vendor::class, 'created_by', 'id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function settlementsApproved()
    {
        return $this->hasMany(Settlement::class, 'approved_by', 'id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'initiated_by', 'id');
    }

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('user_type', $type);
    }

    public function scopePendingVerification($query)
    {
        return $query->where('status', 'pending_verification');
    }

    public function scopeEmailVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function scopePhoneVerified($query)
    {
        return $query->whereNotNull('phone_verified_at');
    }

    public function scopeKycVerified($query)
    {
        return $query->where('kyc_status', 'verified');
    }

    public function scopeByCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    // ========== Accessors ==========

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(substr($this->first_name, 0, 1).substr($this->last_name, 0, 1));
    }

    public function getIsAdminAttribute(): bool
    {
        return $this->user_type === 'admin' || $this->hasRole('admin') || $this->hasRole('super_admin');
    }

    public function getIsVendorAttribute(): bool
    {
        return $this->user_type === 'vendor' || $this->hasRole('vendor');
    }

    public function getIsCustomerAttribute(): bool
    {
        return $this->user_type === 'customer' || $this->hasRole('customer');
    }

    public function getIsMlmAgentAttribute(): bool
    {
        return $this->user_type === 'mlm_agent' || $this->hasRole('mlm_agent');
    }

    public function getIsSuperAdminAttribute(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function getIsLockedAttribute(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    public function getIsEmailVerifiedAttribute(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    public function getIsPhoneVerifiedAttribute(): bool
    {
        return ! is_null($this->phone_verified_at);
    }

    public function getIsKycVerifiedAttribute(): bool
    {
        return $this->kyc_status === 'verified';
    }

    public function getAvatarUrlAttribute($value): string
    {
        if ($value) {
            return $value;
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->full_name).'&background=4F46E5&color=ffffff';
    }

    // ========== Mutators ==========

    public function setPasswordAttribute($value)
    {
        if ($value) {
            $this->attributes['password'] = bcrypt($value);
        }
    }

    public function setEmailAttribute($value)
    {
        $this->attributes['email'] = strtolower(trim($value));
    }

    // ========== Methods ==========

    public function incrementLoginAttempts(): void
    {
        $this->increment('login_attempts');

        if ($this->login_attempts >= 5) {
            $this->locked_until = now()->addMinutes(30);
            $this->save();
        }
    }

    public function resetLoginAttempts(): void
    {
        $this->login_attempts = 0;
        $this->locked_until = null;
        $this->save();
    }

    public function hasMfaEnabled(): bool
    {
        return ! is_null($this->two_factor_secret);
    }

    public function isEmailVerified(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    public function canImpersonate(): bool
    {
        return $this->is_super_admin;
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->is_super_admin;
    }

    public function generateTwoFactorSecret(): string
    {
        $secret = app('pragmarx.google2fa')->generateSecretKey();
        $this->two_factor_secret = $secret;
        $this->save();

        return $secret;
    }

    public function enableTwoFactorAuth(): void
    {
        $this->two_factor_enabled = true;
        $this->save();
    }

    public function disableTwoFactorAuth(): void
    {
        $this->two_factor_secret = null;
        $this->two_factor_recovery_codes = null;
        $this->two_factor_enabled = false;
        $this->save();
    }

    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(substr(md5(uniqid()), 0, 8)).'-'.strtoupper(substr(md5(uniqid()), 0, 8));
        }

        $this->two_factor_recovery_codes = $codes;
        $this->save();

        return $codes;
    }

    public function verifyRecoveryCode($code): bool
    {
        $codes = $this->two_factor_recovery_codes ?? [];

        if (in_array($code, $codes)) {
            $codes = array_diff($codes, [$code]);
            $this->two_factor_recovery_codes = array_values($codes);
            $this->save();

            return true;
        }

        return false;
    }

    public function createToken(
        string $name,
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null
    ): NewAccessToken {
        $token = $this->sanctumCreateToken($name, $abilities, $expiresAt);

        // Store additional metadata
        $token->accessToken->update([
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return $token;
    }

    public function getPermissionArray(): array
    {
        return $this->getAllPermissions()->pluck('name')->toArray();
    }

    public function permissions()
    {
        return $this->morphToMany(
            Permission::class,
            'model',
            'model_has_permissions',
            'model_id',
            'permission_id'
        );
    }

    public function hasPermissionTo($permission): bool
    {
        if (is_string($permission)) {
            return $this->permissions->contains('name', $permission);
        }

        return $this->permissions->contains($permission);
    }

    public function givePermissionTo($permission): void
    {
        if (is_string($permission)) {
            $permission = Permission::where('name', $permission)->firstOrFail();
        }

        $this->permissions()->attach($permission);
    }

    public function revokePermissionTo($permission): void
    {
        if (is_string($permission)) {
            $permission = Permission::where('name', $permission)->firstOrFail();
        }

        $this->permissions()->detach($permission);
    }
}
