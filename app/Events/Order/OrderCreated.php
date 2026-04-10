<?php

namespace App\Events\Order;

use App\Models\Order\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function broadcastOn(): array
    {
        $channels = [];

        // Broadcast to vendor
        if ($this->order->vendor->user) {
            $channels[] = new PrivateChannel('vendor.' . $this->order->vendor->user->id);
        }

        // Broadcast to customer
        if ($this->order->customer_id) {
            $channels[] = new PrivateChannel('customer.' . $this->order->customer_id);
        }

        // Broadcast to admin
        $channels[] = new Channel('admin.orders');

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'order.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => $this->order->status,
            'total' => $this->order->grand_total,
            'created_at' => $this->order->created_at->toIso8601String(),
        ];
    }
}