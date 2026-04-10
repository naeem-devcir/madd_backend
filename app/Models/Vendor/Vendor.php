<?php

namespace App\Models\Vendor;

use App\Models\Traits\HasUuid;
use App\Models\User;
use App\Models\Order\Order;
use App\Models\Financial\Settlement;
use App\Models\Financial\Transaction;
use App\Models\Financial\Payout;
use App\Models\Product\VendorProduct;
use App\Models\Product\ProductDraft;
use App\Models\Review\Review;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $table = 'vendors';

    // protected $primaryKey = 'id';
    // public $incrementing = false;
    // protected $keyType = 'string';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid',
        'user_id',
        'company_name',
        'company_slug',
        'legal_name',
        'trading_name',
        'vat_number',
        'registration_number',
        'country_code',
        'address_line1',
        'address_line2',
        'city',
        'postal_code',
        'contact_email',
        'phone',
        'website',
        'logo_url',
        'banner_url',
        'description',
        'plan_id',
        'plan_starts_at',
        'plan_ends_at',
        'status',
        'onboarding_step',
        'commission_rate',
        'commission_type',
        'commission_override',
        'total_sales',
        'total_commission_paid',
        'total_earned',
        'current_balance',
        'pending_balance',
        'rating_average',
        'total_reviews',
        'mlm_referrer_id',
        'magento_website_id',
        'kyc_status',
        'verification_documents',
        'approved_by',
        'approved_at',
        'timezone',
        'metadata',
    ];

    protected $casts = [
        'plan_starts_at' => 'datetime',
        'plan_ends_at' => 'datetime',
        'approved_at' => 'datetime',
        'verification_documents' => 'array',
        'metadata' => 'array',
        'total_sales' => 'decimal:2',
        'total_commission_paid' => 'decimal:2',
        'total_earned' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'pending_balance' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_override' => 'decimal:2',
        'rating_average' => 'decimal:2',
        'onboarding_step' => 'integer',
    ];

    // ========== Relationships ==========

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'uuid');
    }

    public function plan()
    {
        return $this->belongsTo(VendorPlan::class, 'plan_id', 'id');
    }

    public function stores()
    {
        return $this->hasMany(VendorStore::class, 'vendor_id', 'uuid');
    }

    public function bankAccounts()
    {
        return $this->hasMany(VendorBankAccount::class, 'vendor_id', 'uuid');
    }

    public function primaryBankAccount()
    {
        return $this->hasOne(VendorBankAccount::class, 'vendor_id', 'uuid')->where('is_primary', true);
    }

    public function users()
    {
        return $this->hasMany(VendorUser::class, 'vendor_id', 'uuid');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'vendor_id', 'uuid');
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class, 'vendor_id', 'uuid');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'vendor_id', 'uuid');
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class, 'vendor_id', 'uuid');
    }

    public function products()
    {
        return $this->hasMany(VendorProduct::class, 'vendor_id', 'uuid');
    }

    public function productDrafts()
    {
        return $this->hasMany(ProductDraft::class, 'vendor_id', 'uuid');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'vendor_id', 'uuid');
    }

    public function wallet()
    {
        return $this->hasOne(VendorWallet::class, 'vendor_id', 'uuid');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by', 'uuid');
    }

    public function mlmReferrer()
    {
        return $this->belongsTo(User::class, 'mlm_referrer_id', 'uuid');
    }

    // ========== Scopes ==========

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    public function scopeKycVerified($query)
    {
        return $query->where('kyc_status', 'verified');
    }

    public function scopeKycPending($query)
    {
        return $query->where('kyc_status', 'pending');
    }

    public function scopeByCountry($query, $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    public function scopeByPlan($query, $planId)
    {
        return $query->where('plan_id', $planId);
    }

    // ========== Accessors ==========

    public function getFullAddressAttribute(): string
    {
        $address = $this->address_line1;
        if ($this->address_line2) {
            $address .= ', ' . $this->address_line2;
        }
        $address .= ', ' . $this->city . ', ' . $this->postal_code . ', ' . $this->country_code;

        return $address;
    }

    public function getEffectiveCommissionRateAttribute(): float
    {
        return $this->commission_override ?? $this->commission_rate ?? $this->plan?->commission_rate ?? 0;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    public function getIsSuspendedAttribute(): bool
    {
        return $this->status === 'suspended';
    }

    public function getIsTerminatedAttribute(): bool
    {
        return $this->status === 'terminated';
    }

    public function getIsKycVerifiedAttribute(): bool
    {
        return $this->kyc_status === 'verified';
    }

    public function getPlanIsExpiredAttribute(): bool
    {
        if (!$this->plan_ends_at) {
            return false;
        }

        return $this->plan_ends_at->isPast();
    }

    public function getPlanDaysRemainingAttribute(): ?int
    {
        if (!$this->plan_ends_at) {
            return null;
        }

        return now()->diffInDays($this->plan_ends_at, false);
    }

    public function getLogoUrlAttribute($value): ?string
    {
        if ($value) {
            return $value;
        }

        return 'https://ui-avatars.com/api/?name=' . urlencode($this->company_name) . '&background=4F46E5&color=ffffff&size=120';
    }

    // ========== Methods ==========

    public function canAddProduct(): bool
    {
        $productCount = $this->products()->count();

        return $productCount < $this->plan->max_products;
    }

    public function canAddStore(): bool
    {
        $storeCount = $this->stores()->count();

        return $storeCount < $this->plan->max_stores;
    }

    public function canAddUser(): bool
    {
        $userCount = $this->users()->count();

        return $userCount < $this->plan->max_users;
    }

    public function getCurrentBalanceAttribute(): float
    {
        if ($this->relationLoaded('wallet') && $this->wallet) {
            return $this->wallet->balance;
        }

        $wallet = $this->wallet;

        return $wallet ? $wallet->balance : 0;
    }

    public function updateBalance(float $amount, string $type = 'credit'): void
    {
        $wallet = $this->wallet ?? VendorWallet::create(['vendor_id' => $this->getKey()]);

        if ($type === 'credit') {
            $wallet->increment('balance', $amount);
            $this->increment('total_earned', $amount);
        } else {
            $wallet->decrement('balance', $amount);
        }

        $this->refresh();
    }

    public function reserveBalance(float $amount): void
    {
        $wallet = $this->wallet ?? VendorWallet::create(['vendor_id' => $this->getKey()]);
        $wallet->increment('reserved_balance', $amount);
        $wallet->decrement('balance', $amount);
        $this->increment('pending_balance', $amount);
    }

    public function releaseReservedBalance(float $amount): void
    {
        $wallet = $this->wallet;
        if ($wallet) {
            $wallet->decrement('reserved_balance', $amount);
            $this->decrement('pending_balance', $amount);
        }
    }

    public function activate(): void
    {
        $this->status = 'active';
        $this->approved_at = now();
        $this->save();

        event(new \App\Events\Vendor\VendorApproved($this));
    }

    public function suspend(string $reason = null): void
    {
        $this->status = 'suspended';
        $this->metadata = array_merge($this->metadata ?? [], ['suspension_reason' => $reason, 'suspended_at' => now()]);
        $this->save();
    }

    public function terminate(string $reason = null): void
    {
        $this->status = 'terminated';
        $this->metadata = array_merge($this->metadata ?? [], ['termination_reason' => $reason, 'terminated_at' => now()]);
        $this->save();
    }

    public function updatePlan(VendorPlan $plan, int $durationMonths = 12): void
    {
        $this->plan_id = $plan->id;
        $this->plan_starts_at = now();
        $this->plan_ends_at = now()->addMonths($durationMonths);
        $this->save();
    }

    public function getMonthlyRevenue(): float
    {
        return $this->orders()
            ->where('created_at', '>=', now()->startOfMonth())
            ->where('created_at', '<=', now()->endOfMonth())
            ->sum('grand_total');
    }

    public function getTotalProductsCount(): int
    {
        return $this->products()->count();
    }

    public function getTotalOrdersCount(): int
    {
        return $this->orders()->count();
    }

    public function getAverageOrderValue(): float
    {
        return $this->orders()->avg('grand_total') ?? 0;
    }
}
