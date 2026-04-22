<?php

namespace App\Models\Vendor;

use App\Models\Financial\Payout;
use App\Models\Financial\Settlement;
use App\Models\Financial\Transaction;
use App\Models\Order\Order;
use App\Models\Product\ProductDraft;
use App\Models\Product\VendorProduct;
use App\Models\Review\Review;
use App\Models\Traits\HasUuid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\VendorStatusTrait;


class Vendor extends Model
{
    use HasFactory, HasUuid, SoftDeletes, VendorStatusTrait;

    protected $table = 'vendors';

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
        'plan_duration_months',
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
        'plan_duration_months' => 'integer',
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


    /**
     * Update vendor's plan
     */
    public function updatePlan(VendorPlan $plan, int $durationMonths = 12): void
    {
        $this->update([
            'plan_id' => $plan->id,
            'plan_starts_at' => now(),
            'plan_ends_at' => now()->addMonths($durationMonths),
            'plan_duration_months' => $durationMonths,
        ]);
    }

    /**
     * Check if plan is expired
     */
    public function isPlanExpired(): bool
    {
        return $this->plan_expires_at && now()->gt($this->plan_expires_at);
    }

    /**
     * Get remaining plan days
     */
    public function getRemainingPlanDays(): ?int
    {
        if (!$this->plan_expires_at) {
            return null;
        }

        $days = now()->diffInDays($this->plan_expires_at, false);
        return $days > 0 ? $days : 0;
    }



    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function plan()
    {
        return $this->belongsTo(VendorPlan::class, 'plan_id');
    }

    public function stores()
    {
        return $this->hasMany(VendorStore::class, 'vendor_id');
    }

    public function bankAccounts()
    {
        return $this->hasMany(VendorBankAccount::class, 'vendor_id');
    }

    public function primaryBankAccount()
    {
        return $this->hasOne(VendorBankAccount::class, 'vendor_id')->where('is_primary', true);
    }

    public function users()
    {
        return $this->hasMany(VendorUser::class, 'vendor_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'vendor_id');
    }

    public function settlements()
    {
        return $this->hasMany(Settlement::class, 'vendor_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'vendor_id');
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class, 'vendor_id');
    }

    public function products()
    {
        return $this->hasMany(VendorProduct::class, 'vendor_id');
    }

    public function productDrafts()
    {
        return $this->hasMany(ProductDraft::class, 'vendor_id');
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'vendor_id');
    }

    public function wallet()
    {
        return $this->hasOne(VendorWallet::class, 'vendor_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function mlmReferrer()
    {
        return $this->belongsTo(User::class, 'mlm_referrer_id');
    }

    public function canAddStore()
    {
        $currentStores = $this->stores()->count();

        $maxStores = $this->plan->max_stores ?? 0;

        return $currentStores < $maxStores;
    }
}
