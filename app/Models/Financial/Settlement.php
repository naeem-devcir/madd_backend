<?php

namespace App\Models\Financial;

use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Config\MaddCompany;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Settlement extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'settlements';

    protected $fillable = [
        'payable_type',
        'payable_id',
        'vendor_id',
        'madd_company_id',
        'period_start',
        'period_end',
        'period_days',
        'gross_sales',
        'total_refunds',
        'total_commissions',
        'total_shipping_fees',
        'total_tax_collected',
        'adjustment_amount',
        'gateway_fees',
        'net_payout',
        'currency_code',
        'exchange_rate',
        'status',
        'payment_method',
        'payment_reference',
        'statement_pdf_path',
        'approved_by',
        'approved_at',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'gross_sales' => 'decimal:4',
        'total_refunds' => 'decimal:4',
        'total_commissions' => 'decimal:4',
        'total_shipping_fees' => 'decimal:4',
        'total_tax_collected' => 'decimal:4',
        'adjustment_amount' => 'decimal:4',
        'gateway_fees' => 'decimal:4',
        'net_payout' => 'decimal:4',
        'exchange_rate' => 'decimal:4',
        'period_days' => 'integer',
    ];

    // ========== Relationships ==========
    
    public function payable()
    {
        return $this->morphTo();
    }
    
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }
    
    public function maddCompany()
    {
        return $this->belongsTo(MaddCompany::class, 'madd_company_id', 'id');
    }
    
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by', 'uuid');
    }
    
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'settlement_id', 'uuid');
    }
    
    public function orders()
    {
        return $this->hasMany(Order::class, 'settlement_id', 'id');
    }
    
    public function payout()
    {
        return $this->hasOne(Payout::class, 'settlement_id', 'uuid');
    }
    
    // ========== Scopes ==========
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
    
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }
    
    public function scopeDisputed($query)
    {
        return $query->where('status', 'disputed');
    }
    
    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
    
    public function scopeByPeriod($query, $startDate, $endDate)
    {
        return $query->whereBetween('period_start', [$startDate, $endDate]);
    }
    
    public function scopeForPayable($query, $type, $id)
    {
        return $query->where('payable_type', $type)->where('payable_id', $id);
    }
    
    // ========== Accessors ==========
    
    public function getSettlementNumberAttribute(): string
    {
        return 'SET-' . date('Ymd', strtotime($this->period_start)) . '-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }
    
    public function getFormattedGrossSalesAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->gross_sales, 2);
    }
    
    public function getFormattedNetPayoutAttribute(): string
    {
        return $this->currency_code . ' ' . number_format($this->net_payout, 2);
    }
    
    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }
    
    public function getIsApprovedAttribute(): bool
    {
        return $this->status === 'approved';
    }
    
    public function getIsPaidAttribute(): bool
    {
        return $this->status === 'paid';
    }
    
    public function getIsDisputedAttribute(): bool
    {
        return $this->status === 'disputed';
    }
    
    public function getPeriodRangeAttribute(): string
    {
        return $this->period_start->format('M d, Y') . ' - ' . $this->period_end->format('M d, Y');
    }
    
    // ========== Methods ==========
    
    public function approve(User $approver): void
    {
        $this->status = 'approved';
        $this->approved_by = $approver->uuid;
        $this->approved_at = now();
        $this->save();
        
        // Dispatch event
        event(new \App\Events\Settlement\SettlementApproved($this));
        
        // Generate statement PDF
        \App\Jobs\Settlement\GenerateSettlementStatement::dispatch($this);
    }
    
    public function markAsPaid(string $paymentReference, string $paymentMethod): void
    {
        $this->status = 'paid';
        $this->payment_reference = $paymentReference;
        $this->payment_method = $paymentMethod;
        $this->paid_at = now();
        $this->save();
        
        // Create payout record
        Payout::create([
            'vendor_id' => $this->vendor_id,
            'settlement_id' => $this->uuid,
            'amount' => $this->net_payout,
            'currency' => $this->currency_code,
            'payout_method' => $paymentMethod,
            'gateway_payout_id' => $paymentReference,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        
        // Update vendor balance
        $this->vendor->updateBalance($this->net_payout, 'credit');
        
        // Dispatch event
        event(new \App\Events\Settlement\SettlementPaid($this));
        
        // Send notification to vendor
        \App\Jobs\Notification\SendSettlementNotification::dispatch($this);
    }
    
    public function markAsDisputed(string $reason): void
    {
        $this->status = 'disputed';
        $this->notes = $reason;
        $this->save();
        
        // Notify admin
        \App\Jobs\Notification\SendDisputeNotification::dispatch($this);
    }
    
    public function recalculate(): void
    {
        $orders = Order::whereBetween('created_at', [$this->period_start, $this->period_end])
            ->where('vendor_id', $this->vendor_id)
            ->whereNotNull('settled_at')
            ->get();
        
        $this->gross_sales = $orders->sum('grand_total');
        $this->total_commissions = $orders->sum('commission_amount');
        $this->total_refunds = Refund::whereIn('order_id', $orders->pluck('uuid'))
            ->where('status', 'processed')
            ->sum('refund_amount');
        $this->total_shipping_fees = $orders->sum('shipping_amount');
        $this->total_tax_collected = $orders->sum('tax_amount');
        $this->gateway_fees = $orders->sum('payment_fee');
        
        $this->net_payout = $this->gross_sales 
            - $this->total_commissions 
            - $this->total_refunds 
            - $this->total_shipping_fees
            - $this->gateway_fees
            + $this->adjustment_amount;
            
        $this->save();
    }
    
    public function getTransactionSummary(): array
    {
        return [
            'sales' => $this->transactions()->where('type', 'sale')->sum('amount'),
            'refunds' => $this->transactions()->where('type', 'refund')->sum('amount'),
            'commissions' => $this->transactions()->where('type', 'commission')->sum('amount'),
            'adjustments' => $this->transactions()->where('type', 'adjustment')->sum('amount'),
        ];
    }
}
