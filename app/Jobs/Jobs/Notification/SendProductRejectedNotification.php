<?php

namespace App\Jobs\Notification;

use App\Models\Product\ProductDraft;
use App\Models\Vendor\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendProductRejectedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ProductDraft $draft;
    protected string $reason;
    protected ?Vendor $vendor;

    /**
     * Create a new job instance.
     */
    public function __construct(ProductDraft $draft, string $reason)
    {
        $this->draft = $draft;
        $this->reason = $reason;
        $this->vendor = $draft->vendor;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $vendorEmails = $this->getVendorEmails($this->vendor);
            $adminEmails = config('notifications.admin_emails', []);

            // Send email to vendor
            foreach ($vendorEmails as $vendorEmail) {
                Mail::send('emails.product.rejected', [
                    'draft' => $this->draft,
                    'vendor' => $this->vendor,
                    'reason' => $this->reason,
                    'editUrl' => route('vendor.products.drafts.edit', $this->draft->id),
                ], function ($message) use ($vendorEmail) {
                    $message->to($vendorEmail)
                            ->subject('Product Rejection Notice - ' . $this->draft->name);
                });
            }

            // Send email to admins as confirmation
            foreach ($adminEmails as $adminEmail) {
                Mail::send('emails.product.rejected-admin', [
                    'draft' => $this->draft,
                    'vendor' => $this->vendor,
                    'reason' => $this->reason,
                    'admin' => true,
                ], function ($message) use ($adminEmail) {
                    $message->to($adminEmail)
                            ->subject('Product Rejected - ' . $this->draft->name);
                });
            }

            // Send database notification
            $this->sendDatabaseNotification();

            Log::info('Product rejection notification sent', [
                'draft_id' => $this->draft->id,
                'product_name' => $this->draft->name,
                'vendor_id' => $this->vendor->id,
                'reason' => $this->reason,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send product rejection notification', [
                'draft_id' => $this->draft->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function getVendorEmails(Vendor $vendor): array
    {
        $emails = [];
        
        if ($vendor->email) {
            $emails[] = $vendor->email;
        }
        
        if ($vendor->contact_email) {
            $emails[] = $vendor->contact_email;
        }
        
        return array_unique($emails);
    }

    protected function sendDatabaseNotification(): void
    {
        if (class_exists(\App\Models\VendorNotification::class)) {
            \App\Models\VendorNotification::create([
                'vendor_id' => $this->vendor->id,
                'type' => 'product_rejected',
                'title' => 'Product Rejected',
                'message' => "Your product '{$this->draft->name}' has been rejected. Reason: " . substr($this->reason, 0, 200),
                'data' => [
                    'draft_id' => $this->draft->id,
                    'product_name' => $this->draft->name,
                    'reason' => $this->reason,
                ],
                'read_at' => null,
                'sent_at' => now(),
            ]);
        }
    }
}