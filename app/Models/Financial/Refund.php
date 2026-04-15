<?php

namespace App\Models\Financial;

use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    use HasFactory;

    protected $table = 'refunds';

    protected $fillable = [
        'order_id',
        'payment_transaction_id',
        'refund_amount',
        'reason',
        'status',
        'gateway_refund_id',
        'gateway_response',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:4',
        'gateway_response' => 'array',
        'processed_at' => 'datetime',
    ];

    // ========== Relationships ==========

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function paymentTransaction()
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id', 'id');
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

    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // ========== Accessors ==========

    public function getFormattedAmountAttribute(): string
    {
        return $this->order->currency_code.' '.number_format($this->refund_amount, 2);
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    public function getIsProcessedAttribute(): bool
    {
        return $this->status === 'processed';
    }

    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }

    // ========== Methods ==========

    public function markAsProcessed(string $gatewayRefundId): void
    {
        $this->status = 'processed';
        $this->gateway_refund_id = $gatewayRefundId;
        $this->processed_at = now();
        $this->save();

        // Update order items refund quantity
        // This would be handled by the refund items table in a real implementation

        // Update order totals
        $this->order->updatePaymentStatus('refunded');
    }

    public function markAsFailed(string $error): void
    {
        $this->status = 'failed';
        $this->gateway_response = array_merge($this->gateway_response ?? [], ['error' => $error]);
        $this->save();
    }
}
