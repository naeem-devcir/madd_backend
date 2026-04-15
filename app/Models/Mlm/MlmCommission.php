<?php

namespace App\Models\Mlm;

use App\Models\Financial\Settlement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MlmCommission extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mlm_commissions';

    protected $fillable = [
        'agent_id',
        'settlement_id',
        'source_type',
        'source_id',
        'amount',
        'currency_code',
        'status',
        'description',
        'calculation_snapshot',
        'rejection_reason',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'calculation_snapshot' => 'array',
        'paid_at' => 'datetime',
    ];

    // ========== Relationships ==========

    public function agent()
    {
        return $this->belongsTo(MlmAgent::class, 'agent_id', 'id');
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id', 'id');
    }

    public function source()
    {
        return $this->morphTo();
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

    // ========== Accessors ==========

    public function getFormattedAmountAttribute(): string
    {
        return $this->currency_code.' '.number_format($this->amount, 2);
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

    // ========== Methods ==========

    public function approve(): void
    {
        $this->status = 'approved';
        $this->save();
    }

    public function markAsPaid(string $settlementId): void
    {
        $this->status = 'paid';
        $this->settlement_id = $settlementId;
        $this->paid_at = now();
        $this->save();

        // Update agent total
        $this->agent->increment('total_commissions_earned', $this->amount);
    }

    public function reject(string $reason): void
    {
        $this->status = 'rejected';
        $this->rejection_reason = $reason;
        $this->save();
    }
}
