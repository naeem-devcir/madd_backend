<?php

namespace App\Listeners\Order;

use App\Events\Order\OrderCreated;
use App\Jobs\Notification\SendOrderNotification;
use App\Models\Order\Order;
use App\Services\Notification\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderConfirmation implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [60, 300, 600];

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        try {
            // Send confirmation to customer
            if ($order->customer_id) {
                $this->sendToCustomer($order);
            } else {
                $this->sendToGuest($order);
            }

            // Send notification to vendor
            $this->sendToVendor($order);

            // Send admin notification for high-value orders
            if ($order->grand_total >= 1000) {
                $this->notifyAdmin($order);
            }

            Log::info('Order confirmation sent', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_email' => $order->customer_email,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send order confirmation', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Send confirmation to registered customer
     */
    protected function sendToCustomer(Order $order): void
    {
        $customer = $order->customer;

        // Send email confirmation
        \Mail::to($customer->email)->send(new \App\Mail\Order\OrderConfirmation($order));

        // Send SMS confirmation if phone is verified and opted in
        if ($customer->phone_verified_at && ($customer->preferences['sms']['order_updates'] ?? true)) {
            $this->sendSms($customer->phone, $this->getSmsMessage($order));
        }

        // Send push notification if device tokens exist
        if ($customer->pushDevices()->exists()) {
            $this->sendPushNotification($customer, $order);
        }

        // Create in-app notification
        $this->createInAppNotification($customer, $order);
    }

    /**
     * Send confirmation to guest customer
     */
    protected function sendToGuest(Order $order): void
    {
        // Send email only for guest orders
        \Mail::to($order->customer_email)->send(new \App\Mail\Order\GuestOrderConfirmation($order));
    }

    /**
     * Send notification to vendor
     */
    protected function sendToVendor(Order $order): void
    {
        $vendorUser = $order->vendor->user;

        if ($vendorUser) {
            // Send email to vendor
            \Mail::to($vendorUser->email)->send(new \App\Mail\Order\VendorOrderNotification($order));

            // Create in-app notification for vendor
            $this->createVendorInAppNotification($order, $vendorUser);
        }
    }

    /**
     * Notify admin about high-value order
     */
    protected function notifyAdmin(Order $order): void
    {
        $admins = \App\Models\User::role('admin')->get();

        foreach ($admins as $admin) {
            \Mail::to($admin->email)->send(new \App\Mail\Order\HighValueOrderAlert($order));
        }
    }

    /**
     * Send SMS message
     */
    protected function sendSms(string $phone, string $message): void
    {
        try {
            $twilio = new \Twilio\Rest\Client(
                config('services.twilio.sid'),
                config('services.twilio.token')
            );

            $twilio->messages->create($phone, [
                'from' => config('services.twilio.phone_number'),
                'body' => $message,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send SMS', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Send push notification
     */
    protected function sendPushNotification($customer, Order $order): void
    {
        $tokens = $customer->pushDevices()->pluck('device_token')->toArray();

        if (empty($tokens)) {
            return;
        }

        try {
            $payload = [
                'title' => 'Order Confirmed!',
                'body' => 'Your order #' . $order->order_number . ' has been confirmed.',
                'data' => [
                    'type' => 'order_confirmation',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'screen' => 'OrderDetails',
                ],
            ];

            // Send to Firebase Cloud Messaging
            $factory = new \Kreait\Firebase\Factory();
            $firebase = $factory->withServiceAccount(config('services.firebase.credentials'))->createMessaging();

            $firebase->sendMulticast($payload, $tokens);

        } catch (\Exception $e) {
            Log::warning('Failed to send push notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Create in-app notification for customer
     */
    protected function createInAppNotification($customer, Order $order): void
    {
        $customer->notifications()->create([
            'type' => 'order_confirmation',
            'channel' => 'in_app',
            'title' => [
                'en' => 'Order Confirmed!',
                'de' => 'Bestellung bestätigt!',
                'fr' => 'Commande confirmée!',
            ],
            'body' => [
                'en' => 'Your order #' . $order->order_number . ' has been confirmed.',
                'de' => 'Ihre Bestellung #' . $order->order_number . ' wurde bestätigt.',
                'fr' => 'Votre commande #' . $order->order_number . ' a été confirmée.',
            ],
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            'action_url' => '/account/orders/' . $order->id,
            'priority' => 'high',
        ]);
    }

    /**
     * Create in-app notification for vendor
     */
    protected function createVendorInAppNotification(Order $order, $vendorUser): void
    {
        $vendorUser->notifications()->create([
            'type' => 'new_order',
            'channel' => 'in_app',
            'title' => [
                'en' => 'New Order Received!',
                'de' => 'Neue Bestellung erhalten!',
                'fr' => 'Nouvelle commande reçue!',
            ],
            'body' => [
                'en' => 'Order #' . $order->order_number . ' from ' . $order->customer_email . ' - Total: ' . $order->currency_code . ' ' . number_format($order->grand_total, 2),
                'de' => 'Bestellung #' . $order->order_number . ' von ' . $order->customer_email . ' - Gesamt: ' . $order->currency_code . ' ' . number_format($order->grand_total, 2),
                'fr' => 'Commande #' . $order->order_number . ' de ' . $order->customer_email . ' - Total: ' . $order->currency_code . ' ' . number_format($order->grand_total, 2),
            ],
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'customer_email' => $order->customer_email,
                'total' => $order->grand_total,
            ],
            'action_url' => '/vendor/orders/' . $order->id,
            'priority' => 'high',
        ]);
    }

    /**
     * Get SMS message
     */
    protected function getSmsMessage(Order $order): string
    {
        return "Order #{$order->order_number} confirmed! Total: {$order->currency_code} " . number_format($order->grand_total, 2) . ". Track your order: " . config('app.url') . "/account/orders/{$order->id}";
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('SendOrderConfirmation listener failed', [
            'order_id' => $this->order->id ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}