<?php

namespace App\Events\Payment;

use App\Models\Order\Order;
use App\Models\Financial\PaymentTransaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $paymentTransaction;

    public function __construct(Order $order, PaymentTransaction $paymentTransaction)
    {
        $this->order = $order;
        $this->paymentTransaction = $paymentTransaction;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('vendor.' . $this->order->vendor->user_id),
            new Channel('admin.payments'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'payment.received';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'amount' => $this->paymentTransaction->amount,
            'currency' => $this->paymentTransaction->currency,
            'payment_method' => $this->paymentTransaction->payment_method_details['method'] ?? 'unknown',
            'received_at' => now()->toIso8601String(),
        ];
    }
}