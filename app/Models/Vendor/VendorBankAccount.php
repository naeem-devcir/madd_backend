<?php

namespace App\Models\Vendor;

use App\Jobs\Notification\SendBankVerificationNotification;
use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorBankAccount extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'vendor_banking';

    protected $fillable = [
        'uuid',
        'vendor_id',
        'account_type',
        'bank_name',
        'iban',
        'bic_swift',
        'paypal_email',
        'stripe_account_id',
        'account_holder_name',
        'currency_code',
        'is_primary',
        'is_verified',
        'verification_doc_path',
        'rejection_reason',
        'last_verified_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'is_verified' => 'boolean',
        'last_verified_at' => 'datetime',
    ];

    protected $hidden = [
        'iban',
        'bic_swift',
        'paypal_email',
        'stripe_account_id',
    ];

    // ========== Relationships ==========

    /**
     * Get the vendor that owns this bank account
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    // ========== Scopes ==========

    /**
     * Scope to bank accounts
     */
    public function scopeBank($query)
    {
        return $query->where('account_type', 'bank');
    }

    /**
     * Scope to PayPal accounts
     */
    public function scopePaypal($query)
    {
        return $query->where('account_type', 'paypal');
    }

    /**
     * Scope to Stripe accounts
     */
    public function scopeStripe($query)
    {
        return $query->where('account_type', 'stripe');
    }

    /**
     * Scope to primary accounts
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope to verified accounts
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope to pending verification accounts
     */
    public function scopePendingVerification($query)
    {
        return $query->where('is_verified', false);
    }

    /**
     * Scope by currency
     */
    public function scopeByCurrency($query, $currencyCode)
    {
        return $query->where('currency_code', $currencyCode);
    }

    // ========== Accessors ==========

    /**
     * Get masked IBAN for display (shows only last 4 characters)
     */
    public function getMaskedIbanAttribute(): ?string
    {
        if (! $this->iban) {
            return null;
        }

        $length = strlen($this->iban);

        return '••••'.substr($this->iban, -4);
    }

    /**
     * Get masked PayPal email
     */
    public function getMaskedPaypalEmailAttribute(): ?string
    {
        if (! $this->paypal_email) {
            return null;
        }

        $parts = explode('@', $this->paypal_email);
        $maskedLocal = substr($parts[0], 0, 2).'•••'.substr($parts[0], -2);

        return $maskedLocal.'@'.$parts[1];
    }

    /**
     * Get account display name
     */
    public function getDisplayNameAttribute(): string
    {
        switch ($this->account_type) {
            case 'bank':
                return $this->bank_name.' (••••'.substr($this->iban, -4).')';
            case 'paypal':
                return 'PayPal: '.$this->masked_paypal_email;
            case 'stripe':
                return 'Stripe Connect';
            default:
                return 'Unknown';
        }
    }

    /**
     * Get account type label
     */
    public function getAccountTypeLabelAttribute(): string
    {
        $labels = [
            'bank' => 'Bank Account',
            'paypal' => 'PayPal',
            'stripe' => 'Stripe Connect',
        ];

        return $labels[$this->account_type] ?? ucfirst($this->account_type);
    }

    /**
     * Get account type icon
     */
    public function getAccountTypeIconAttribute(): string
    {
        $icons = [
            'bank' => '🏦',
            'paypal' => '💰',
            'stripe' => '⚡',
        ];

        return $icons[$this->account_type] ?? '💳';
    }

    /**
     * Check if account can be used for payouts
     */
    public function getIsPayoutReadyAttribute(): bool
    {
        return $this->is_verified && ($this->account_type !== 'bank' || ($this->iban && $this->bic_swift));
    }

    /**
     * Get verification status label
     */
    public function getVerificationStatusLabelAttribute(): string
    {
        if ($this->is_verified) {
            return 'Verified';
        }

        if ($this->rejection_reason) {
            return 'Rejected';
        }

        return 'Pending Verification';
    }

    /**
     * Get verification status color
     */
    public function getVerificationStatusColorAttribute(): string
    {
        if ($this->is_verified) {
            return 'green';
        }

        if ($this->rejection_reason) {
            return 'red';
        }

        return 'yellow';
    }

    // ========== Methods ==========

    /**
     * Mark account as primary
     */
    public function markAsPrimary(): void
    {
        // Remove primary flag from other accounts
        $this->vendor->bankAccounts()->update(['is_primary' => false]);

        // Set this account as primary
        $this->is_primary = true;
        $this->save();
    }

    /**
     * Verify the bank account
     */
    public function verify(?string $verifiedBy = null): void
    {
        $this->is_verified = true;
        $this->rejection_reason = null;
        $this->last_verified_at = now();
        $this->save();

        // If this is the only verified account, make it primary
        if ($this->vendor->bankAccounts()->where('is_verified', true)->count() === 1) {
            $this->markAsPrimary();
        }
    }

    /**
     * Reject the bank account verification
     */
    public function reject(string $reason): void
    {
        $this->is_verified = false;
        $this->rejection_reason = $reason;
        $this->save();
    }

    /**
     * Submit for verification
     */
    public function submitForVerification(string $documentPath): void
    {
        $this->verification_doc_path = $documentPath;
        $this->is_verified = false;
        $this->rejection_reason = null;
        $this->save();

        // Notify admin for verification
        SendBankVerificationNotification::dispatch($this);
    }

    /**
     * Get Stripe Connect account link (for onboarding)
     */
    public function getStripeOnboardingLink(): ?string
    {
        if ($this->account_type !== 'stripe') {
            return null;
        }

        // This would call Stripe API to create an account link
        // For implementation, you would use:
        // $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
        // $link = $stripe->accountLinks->create([...]);

        return null;
    }

    /**
     * Validate bank account format
     */
    public function isValidFormat(): bool
    {
        switch ($this->account_type) {
            case 'bank':
                return ! empty($this->iban) && ! empty($this->bic_swift) && ! empty($this->account_holder_name);
            case 'paypal':
                return ! empty($this->paypal_email) && filter_var($this->paypal_email, FILTER_VALIDATE_EMAIL);
            case 'stripe':
                return ! empty($this->stripe_account_id);
            default:
                return false;
        }
    }
}
