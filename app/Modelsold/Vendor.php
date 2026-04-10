<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    protected $fillable = [
        'user_id',
        'company_name',
        'company_slug',
        'vat_number',
        'registration_number',
        'country_code',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'plan_id',
        'plan_starts_at',
        'plan_ends_at',
        'status',
        'onboarding_step',
        'mlm_referrer_id',
        'approved_by',
        'approved_at',
        'magento_website_id',
        'logo_url',
        'kyc_status',
        'commission_override',
        'contact_email',
        'timezone',
    ];

    protected $casts = [
        'plan_starts_at'      => 'datetime',
        'plan_ends_at'        => 'datetime',
        'approved_at'         => 'datetime',
        'commission_override' => 'decimal:2',
        'onboarding_step'     => 'integer',
        'magento_website_id'  => 'integer',
        // These reference users.id which is auto-increment integer
        'user_id'             => 'integer',
        'mlm_referrer_id'     => 'integer',
        'approved_by'         => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Owner user account — references users.id (integer)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Subscription plan — no FK constraint yet (vendor_plans pending)
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(VendorPlan::class, 'plan_id');
    }

    /**
     * MLM agent who referred this vendor — references users.id (integer)
     */
    public function mlmReferrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mlm_referrer_id');
    }

    /**
     * Admin who approved this vendor — references users.id (integer)
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    public function scopeKycVerified($query)
    {
        return $query->where('kyc_status', 'verified');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPlanActive(): bool
    {
        return $this->plan_ends_at !== null && $this->plan_ends_at->isFuture();
    }

    public function isKycVerified(): bool
    {
        return $this->kyc_status === 'verified';
    }
}