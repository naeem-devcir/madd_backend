<?php

namespace App\Models\Financial;

use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transactions';

    protected $fillable = [
        'settlement_id',
        'order_id',
        'vendor_id',
        'initiated_by',
        'payable_type',
        'payable_id',
        'type',
        'status',
        'amount',
        'gateway_fee',
        'currency_code',
        'balance_after',
        'gateway',
        'gateway_transaction_id',
        'reference',
        'failure_reason',
        'description',
        'metadata',
        'processed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'gateway_fee' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    public $timestamps = false;
    
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;

    // ========== Relationships ==========
    
    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id', 'uuid');
    }
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'uuid');
    }
    
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'uuid');
    }
    
    public function initiatedBy()
    {
        return $this->belongsTo(User::class, 'initiated_by', 'uuid');
    }
    
    public function payable()
    {
        return $this->morphTo();
    }
    
    // ========== Scopes ==========
    
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
    
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
    
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
    
    public function scopeByVendor($query, $vendorId)
    {
        return $query->where('vendor_id', $vendorId);
    }
    
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
    
    // ========== Accessors ==========
    
    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->amount < 0 ? '-' : '';
        return $prefix . $this->currency_code . ' ' . number_format(abs($this->amount), 2);
    }
    
    public function getIsCreditAttribute(): bool
    {
        return $this->amount > 0;
    }
    
    public function getIsDebitAttribute(): bool
    {
        return $this->amount < 0;
    }
    
    public function getTypeLabelAttribute(): string
    {
        $labels = [
            'sale' => 'Sale',
            'refund' => 'Refund',
            'commission' => 'Commission',
            'adjustment' => 'Adjustment',
            'payout' => 'Payout',
        ];
        
        return $labels[$this->type] ?? ucfirst($this->type);
    }
    
    // ========== Methods ==========
    
    public function markAsCompleted(): void
    {
        $this->status = 'completed';
        $this->processed_at = now();
        $this->save();
    }
    
    public function markAsFailed(string $reason): void
    {
        $this->status = 'failed';
        $this->failure_reason = $reason;
        $this->processed_at = now();
        $this->save();
    }
    
    public function markAsReversed(): void
    {
        $this->status = 'reversed';
        $this->save();
    }
}
