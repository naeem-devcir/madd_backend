<?php

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialAccount extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'social_accounts';

    protected $fillable = [
        'uuid',
        'user_id',
        'provider',
        'provider_id',
        'provider_email',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    // ========== Relationships ==========

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // ========== Scopes ==========

    public function scopeByProvider($query, $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeByProviderId($query, $providerId)
    {
        return $query->where('provider_id', $providerId);
    }

    // ========== Accessors ==========

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    // ========== Methods ==========

    public function updateToken(string $accessToken, ?string $refreshToken = null, ?\DateTime $expiresAt = null): void
    {
        $this->access_token = $accessToken;

        if ($refreshToken) {
            $this->refresh_token = $refreshToken;
        }

        if ($expiresAt) {
            $this->expires_at = $expiresAt;
        }

        $this->save();
    }
}
