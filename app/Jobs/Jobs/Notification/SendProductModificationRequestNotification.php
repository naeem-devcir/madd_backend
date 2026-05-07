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

class SendProductModificationRequestNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ProductDraft $draft;
    protected string $notes;
    protected ?Vendor $vendor;

    /**
     * Create a new job instance.
     */
    public function __construct(ProductDraft $draft, string $notes)
    {
        $this->draft = $draft;
        $this->notes = $notes;
        $this->vendor = $draft->vendor;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $vendorEmails = $this->getVendorEmails($this->vendor);

            // Send email to vendor
            foreach ($vendorEmails as $vendorEmail) {
                Mail::send('emails.product.modification-request', [
                    'draft' => $this->draft,
                    'vendor' => $this->vendor,
                    'notes' => $this->notes,
                    'editUrl' => route('vendor.products.drafts.edit', $this->draft->id),
                ], function ($message) use ($vendorEmail) {
                    $message->to($vendorEmail)
                            ->subject('Product Modification Request - ' . $this->draft->name)
                            ->priority(1);
                });
            }

            // Send reminder notification if configured
            $this->scheduleReminder();

            // Send database notification
            $this->sendDatabaseNotification();

            Log::info('Product modification request notification sent', [
                'draft_id' => $this->draft->id,
                'product_name' => $this->draft->name,
                'vendor_id' => $this->vendor->id,
                'notes' => substr($this->notes, 0, 100),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send product modification request notification', [
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

    protected function scheduleReminder(): void
    {
        // Schedule a reminder if vendor doesn't respond in 3 days
        $reminderDelay = now()->addDays(3);
        
        dispatch(new \App\Jobs\Notification\SendModificationReminderNotification($this->draft, $this->notes))
            ->delay($reminderDelay);
    }

    protected function sendDatabaseNotification(): void
    {
        if (class_exists(\App\Models\VendorNotification::class)) {
            \App\Models\VendorNotification::create([
                'vendor_id' => $this->vendor->id,
                'type' => 'product_modification_requested',
                'title' => 'Product Changes Requested',
                'message' => "Please modify your product '{$this->draft->name}'. Notes: " . substr($this->notes, 0, 150),
                'data' => [
                    'draft_id' => $this->draft->id,
                    'product_name' => $this->draft->name,
                    'notes' => $this->notes,
                    'action_url' => route('vendor.products.drafts.edit', $this->draft->id),
                ],
                'read_at' => null,
                'sent_at' => now(),
            ]);
        }
    }
}