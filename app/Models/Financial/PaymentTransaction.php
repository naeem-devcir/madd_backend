<?php

namespace App\Models\Financial;

use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $table = 'payment_transactions';

    protected $fillable = [
        'order_id',
        'gateway',
        'gateway_transaction_id',
        'parent_transaction_id',
        'transaction_type',
        'amount',
        'currency',
        'status',
        'payment_method_details',
        'customer_ip',
        'card_last4',
        'card_brand',
        'fraud_status',
        'fraud_score',
        'gateway_request',
        'gateway_response',
        'error_message',
        'captured_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'fraud_score' => 'decimal:2',
        'payment_method_details' => 'array',
        'gateway_request' => 'array',
        'gateway_response' => 'array',
        'captured_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    // ========== Relationships ==========
    
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'uuid');
    }
    
    public function parentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class, 'parent_transaction_id', 'gateway_transaction_id');
    }
    
    public function refunds()
    {
        return $this->hasMany(Refund::class, 'payment_transaction_id', 'uuid');
    }
    
    // ========== Scopes ==========
    
    public function scopeAuthorized($query)
    {
        return $query->where('status', 'authorized');
    }
    
    public function scopeCaptured($query)
    {
        return $query->where('status', 'captured');
    }
    
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
    
    // ========== Accessors ==========
    
    public function getIsAuthorizedAttribute(): bool
    {
        return $this->status === 'authorized';
    }
    
    public function getIsCapturedAttribute(): bool
    {
        return $this->status === 'captured';
    }
    
    public function getIsRefundedAttribute(): bool
    {
        return $this->status === 'refunded';
    }
    
    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }
    
    public function getFormattedAmountAttribute(): string
    {
        return $this->currency . ' ' . number_format($this->amount, 2);
    }
    
    // ========== Methods ==========
    
    public function markAsCaptured(string $captureId): void
    {
        $this->status = 'captured';
        $this->gateway_transaction_id = $captureId;
        $this->captured_at = now();
        $this->save();
        
        // Update order payment status
        $this->order->updatePaymentStatus('paid');
    }
    
    public function markAsFailed(string $error): void
    {
        $this->status = 'failed';
        $this->error_message = $error;
        $this->save();
        
        // Update order payment status
        $this->order->updatePaymentStatus('failed', $error);
    }
    
    public function refund(float $amount, string $reason): Refund
    {
        $refund = Refund::create([
            'order_id' => $this->order_id,
            'payment_transaction_id' => $this->uuid,
            'refund_amount' => $amount,
            'reason' => $reason,
            'status' => 'pending',
        ]);
        
        return $refund;
    }
}
