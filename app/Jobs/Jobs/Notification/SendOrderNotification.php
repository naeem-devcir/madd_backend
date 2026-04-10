<?php

namespace App\Jobs\Jobs\Notification;

use App\Models\Order\Order;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendOrderNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;
    protected $eventType;
    protected $recipientType;

    public $tries = 3;
    public $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     *
     * @param Order $order
     * @param string $eventType (created, shipped, delivered, cancelled, refunded)
     * @param string|null $recipientType (customer, vendor, both)
     */
    public function __construct(Order $order, string $eventType, ?string $recipientType = 'both')
    {
        $this->order = $order;
        $this->eventType = $eventType;
        $this->recipientType = $recipientType;
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            // Send to customer
            if ($this->recipientType === 'customer' || $this->recipientType === 'both') {
                $this->sendToCustomer($notificationService);
            }

            // Send to vendor
            if ($this->recipientType === 'vendor' || $this->recipientType === 'both') {
                $this->sendToVendor($notificationService);
            }

            Log::info('Order notification sent', [
                'order_id' => $this->order->id,
                'event_type' => $this->eventType,
                'recipient_type' => $this->recipientType,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send order notification', [
                'order_id' => $this->order->id,
                'event_type' => $this->eventType,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Send notification to customer.
     */
    protected function sendToCustomer(NotificationService $service): void
    {
        $data = $this->getNotificationData('customer');

        if ($this->order->customer_id) {
            $customer = $this->order->customer;
            $service->send($customer, $data);
        } elseif ($this->order->customer_email) {
            // Send email for guest checkout
            $service->sendEmail($this->order->customer_email, [
                'type' => 'order_' . $this->eventType,
                'subject' => $data['title'],
                'body' => $data['body'],
                'data' => $data['data'],
            ]);
        }
    }

    /**
     * Send notification to vendor.
     */
    protected function sendToVendor(NotificationService $service): void
    {
        // Only send certain events to vendor
        $vendorEvents = ['created', 'cancelled', 'refunded'];
        
        if (!in_array($this->eventType, $vendorEvents)) {
            return;
        }

        $vendor = $this->order->vendor->user;
        
        if ($vendor) {
            $data = $this->getNotificationData('vendor');
            $service->send($vendor, $data);
        }
    }

    /**
     * Get notification data based on recipient and event type.
     */
    protected function getNotificationData(string $recipient): array
    {
        $titles = [
            'customer' => [
                'created' => 'Order Confirmed! 🎉',
                'shipped' => 'Your Order Has Shipped! 📦',
                'delivered' => 'Order Delivered ✅',
                'cancelled' => 'Order Cancelled ❌',
                'refunded' => 'Refund Processed 💰',
            ],
            'vendor' => [
                'created' => 'New Order Received! 🎉',
                'cancelled' => 'Order Cancelled ❌',
                'refunded' => 'Refund Requested 💰',
            ],
        ];

        $bodies = [
            'customer' => [
                'created' => "Thank you for your purchase! Your order #{$this->order->order_number} has been confirmed. We'll notify you when it ships.",
                'shipped' => "Great news! Your order #{$this->order->order_number} is on its way. Track your shipment to see when it will arrive.",
                'delivered' => "Your order #{$this->order->order_number} has been delivered. We hope you love your purchase!",
                'cancelled' => "Your order #{$this->order->order_number} has been cancelled. If you didn't request this, please contact support.",
                'refunded' => "A refund of {$this->order->currency_code} " . number_format($this->order->grand_total, 2) . " has been processed for order #{$this->order->order_number}.",
            ],
            'vendor' => [
                'created' => "You've received a new order #{$this->order->order_number} from {$this->order->customer_name}. Total: {$this->order->currency_code} " . number_format($this->order->grand_total, 2),
                'cancelled' => "Order #{$this->order->order_number} has been cancelled by the customer.",
                'refunded' => "A refund has been requested for order #{$this->order->order_number}. Please review and process.",
            ],
        ];

        return [
            'type' => 'order_' . $this->eventType,
            'title' => $titles[$recipient][$this->eventType] ?? 'Order Update',
            'body' => $bodies[$recipient][$this->eventType] ?? 'Your order has been updated.',
            'priority' => $this->eventType === 'created' ? 'high' : 'medium',
            'data' => [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status,
                'total' => $this->order->grand_total,
                'currency' => $this->order->currency_code,
                'event_type' => $this->eventType,
            ],
            'channels' => $recipient === 'customer' ? ['email', 'in_app', 'push'] : ['email', 'in_app'],
        ];
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Order notification job failed', [
            'order_id' => $this->order->id,
            'event_type' => $this->eventType,
            'error' => $exception->getMessage(),
        ]);
    }
}