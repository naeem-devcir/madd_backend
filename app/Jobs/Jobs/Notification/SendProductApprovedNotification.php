<?php

namespace App\Jobs\Notification;

use App\Models\Product\VendorProduct;
use App\Models\Vendor\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendProductApprovedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected VendorProduct $product;

    /**
     * Create a new job instance.
     */
    public function __construct(VendorProduct $product)
    {
        $this->product = $product;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $vendor = $this->product->vendor;
            $vendorEmails = $this->getVendorEmails($vendor);

            if (empty($vendorEmails)) {
                Log::warning('No vendor emails found for product approval notification', [
                    'product_id' => $this->product->id,
                    'vendor_id' => $vendor->id,
                ]);
                return;
            }

            // Send email to vendor
            foreach ($vendorEmails as $vendorEmail) {
                Mail::send('emails.product.approved', [
                    'product' => $this->product,
                    'vendor' => $vendor,
                    'productUrl' => route('vendor.products.show', $this->product->id),
                ], function ($message) use ($vendorEmail) {
                    $message->to($vendorEmail)
                            ->subject('Your Product Has Been Approved - ' . $this->product->name);
                });
            }

            // Send SMS if configured
            $this->sendSmsNotification($vendor);

            // Send database notification
            $this->sendDatabaseNotification($vendor);

            // Send push notification
            $this->sendPushNotification($vendor);

            Log::info('Product approved notification sent', [
                'product_id' => $this->product->id,
                'product_name' => $this->product->name,
                'vendor_id' => $vendor->id,
                'emails_sent' => count($vendorEmails),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send product approved notification', [
                'product_id' => $this->product->id,
                'error' => $e->getMessage(),
            ]);
            
            // Don't throw exception to prevent job retry for non-critical notifications
        }
    }

    protected function getVendorEmails(Vendor $vendor): array
    {
        $emails = [];
        
        // Add vendor primary email
        if ($vendor->email) {
            $emails[] = $vendor->email;
        }
        
        // Add vendor contact emails
        if ($vendor->contact_email) {
            $emails[] = $vendor->contact_email;
        }
        
        // Add store emails if any
        foreach ($vendor->stores as $store) {
            if ($store->email) {
                $emails[] = $store->email;
            }
        }
        
        return array_unique($emails);
    }

    protected function sendSmsNotification(Vendor $vendor): void
    {
        $smsEnabled = config('notifications.sms_enabled', false);
        
        if ($smsEnabled && $vendor->phone) {
            // Implement SMS sending logic
            // You can use Twilio, Vonage, or any SMS service
            Log::info('SMS notification would be sent', [
                'vendor_id' => $vendor->id,
                'phone' => $vendor->phone,
                'product' => $this->product->name,
            ]);
        }
    }

    protected function sendDatabaseNotification(Vendor $vendor): void
    {
        if (class_exists(\App\Models\VendorNotification::class)) {
            \App\Models\VendorNotification::create([
                'vendor_id' => $vendor->id,
                'type' => 'product_approved',
                'title' => 'Product Approved',
                'message' => "Your product '{$this->product->name}' has been approved and is now live",
                'data' => [
                    'product_id' => $this->product->id,
                    'product_name' => $this->product->name,
                ],
                'read_at' => null,
                'sent_at' => now(),
            ]);
        }
    }

    protected function sendPushNotification(Vendor $vendor): void
    {
        // Implement push notification for mobile app
        // You can use Firebase Cloud Messaging (FCM) or OneSignal
        if ($vendor->push_token) {
            Log::info('Push notification would be sent', [
                'vendor_id' => $vendor->id,
                'push_token' => $vendor->push_token,
            ]);
        }
    }
}