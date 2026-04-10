<?php

namespace App\Models\Config;

use App\Models\Vendor\VendorStore;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Domain extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'domains';

    protected $fillable = [
        'vendor_store_id',
        'domain',
        'type',
        'dns_verified',
        'dns_verified_at',
        'verification_token',
        'ssl_status',
        'ssl_provider',
        'ssl_issued_at',
        'ssl_expires_at',
        'ssl_auto_renew',
        'expires_at',
        'redirect_type',
        'www_redirect',
        'registrar',
        'is_primary',
    ];

    protected $casts = [
        'dns_verified' => 'boolean',
        'dns_verified_at' => 'datetime',
        'ssl_issued_at' => 'datetime',
        'ssl_expires_at' => 'datetime',
        'expires_at' => 'datetime',
        'ssl_auto_renew' => 'boolean',
        'www_redirect' => 'boolean',
        'is_primary' => 'boolean',
    ];

    // ========== Relationships ==========

    public function store()
    {
        return $this->belongsTo(VendorStore::class, 'vendor_store_id', 'uuid');
    }

    // ========== Scopes ==========

    public function scopeVerified($query)
    {
        return $query->where('dns_verified', true);
    }

    public function scopeSslActive($query)
    {
        return $query->where('ssl_status', 'active');
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    // ========== Accessors ==========

    public function getFullUrlAttribute(): string
    {
        $protocol = $this->ssl_status === 'active' ? 'https' : 'http';
        return $protocol . '://' . $this->domain;
    }

    public function getIsDnsVerifiedAttribute(): bool
    {
        return (bool) $this->dns_verified;
    }

    public function getIsSslActiveAttribute(): bool
    {
        return $this->ssl_status === 'active';
    }

    public function getIsSslExpiredAttribute(): bool
    {
        return $this->ssl_status === 'expired' || ($this->ssl_expires_at && $this->ssl_expires_at->isPast());
    }

    // ========== Methods ==========

    public function verifyDns(): void
    {
        // This would call a DNS verification service
        // For now, we'll simulate
        $this->dns_verified = true;
        $this->dns_verified_at = now();
        $this->save();
    }

    public function issueSslCertificate(): void
    {
        // This would call Let's Encrypt API
        // For now, we'll simulate
        $this->ssl_status = 'active';
        $this->ssl_issued_at = now();
        $this->ssl_expires_at = now()->addDays(90);
        $this->save();
    }

    public function renewSslCertificate(): void
    {
        if ($this->ssl_auto_renew && $this->ssl_expires_at && $this->ssl_expires_at->diffInDays(now()) <= 30) {
            $this->issueSslCertificate();
        }
    }


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
