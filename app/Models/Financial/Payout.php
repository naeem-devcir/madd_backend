<?php

namespace App\Models\Financial;

use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    use HasFactory;

    protected $table = 'vendor_payouts';

    protected $fillable = [
        'vendor_id',
        'settlement_id',
        'amount',
        'currency',
        'payout_method',
        'payout_account_details',
        'status',
        'gateway_payout_id',
        'gateway_response',
        'processed_by',
        'processed_at',
        'completed_at',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'payout_account_details' => 'array',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ========== Relationships ==========

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id', 'id');
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by', 'id');
    }

    // ========== Scopes ==========

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // ========== Accessors ==========

    public function getFormattedAmountAttribute(): string
    {
        return $this->currency.' '.number_format($this->amount, 2);
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    public function getIsProcessingAttribute(): bool
    {
        return $this->status === 'processing';
    }

    public function getIsCompletedAttribute(): bool
    {
        return $this->status === 'completed';
    }

    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }

    // ========== Methods ==========

    public function markAsProcessing(): void
    {
        $this->status = 'processing';
        $this->processed_at = now();
        $this->save();
    }

    public function markAsCompleted(string $gatewayPayoutId): void
    {
        $this->status = 'completed';
        $this->gateway_payout_id = $gatewayPayoutId;
        $this->completed_at = now();
        $this->save();

        // Update settlement if linked
        if ($this->settlement_id) {
            $this->settlement->markAsPaid($gatewayPayoutId, $this->payout_method);
        }
    }

    public function markAsFailed(string $reason): void
    {
        $this->status = 'failed';
        $this->failure_reason = $reason;
        $this->save();
    }
}
