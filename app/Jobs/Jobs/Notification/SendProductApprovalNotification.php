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

class SendProductApprovalNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ProductDraft $draft;
    protected ?Vendor $vendor;

    /**
     * Create a new job instance.
     */
    public function __construct(ProductDraft $draft)
    {
        $this->draft = $draft;
        $this->vendor = $draft->vendor;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Get admin emails from configuration
            $adminEmails = config('notifications.admin_emails', []);
            
            if (empty($adminEmails)) {
                Log::warning('No admin emails configured for product approval notifications');
                return;
            }

            // Send email to admins
            foreach ($adminEmails as $adminEmail) {
                Mail::send('emails.product.approval-request', [
                    'draft' => $this->draft,
                    'vendor' => $this->vendor,
                    'approvalUrl' => route('admin.products.approvals.show', $this->draft->id),
                ], function ($message) use ($adminEmail) {
                    $message->to($adminEmail)
                            ->subject('New Product Approval Request - ' . $this->draft->name)
                            ->priority(1); // High priority
                });
            }

            // Send database notification
            $this->sendDatabaseNotification();

            // Send to admin notification channels (Slack, etc.)
            $this->sendSlackNotification();

            Log::info('Product approval notification sent', [
                'draft_id' => $this->draft->id,
                'product_name' => $this->draft->name,
                'vendor_id' => $this->vendor->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send product approval notification', [
                'draft_id' => $this->draft->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    protected function sendDatabaseNotification(): void
    {
        // Assuming you have a Notification model
        if (class_exists(App\Models\Notification\Notification::class)) {
            App\Models\Notification\Notification::create([
                'type' => 'product_approval_needed',
                'title' => 'New Product Approval Request',
                'message' => "Product '{$this->draft->name}' from vendor '{$this->vendor->name}' requires approval",
                'data' => [
                    'draft_id' => $this->draft->id,
                    'vendor_id' => $this->vendor->id,
                    'product_name' => $this->draft->name,
                ],
                'notifiable_type' => 'admin',
                'notifiable_id' => null, // Will be sent to all admins
                'sent_at' => now(),
            ]);
        }
    }

    protected function sendSlackNotification(): void
    {
        $webhookUrl = config('notifications.slack_admin_webhook');
        
        if ($webhookUrl) {
            // Implement Slack notification if needed
            // You can use Laravel's notification system or a package like laravel-notification-channels/slack
        }
    }
}