<?php

namespace App\Listeners\Vendor;

use App\Events\Vendor\VendorApproved;
use App\Mail\Vendor\VendorWelcomeEmail;
use App\Services\Notification\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmail implements ShouldQueue
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
     * Notification service instance
     */
    protected $notificationService;

    /**
     * Create the event listener.
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     */
    public function handle(VendorApproved $event): void
    {
        $vendor = $event->vendor;
        $user = $vendor->user;

        try {
            // Send welcome email
            $this->sendWelcomeEmail($vendor, $user);

            // Send SMS welcome message
            if ($user->phone && $user->phone_verified_at) {
                $this->sendWelcomeSms($user);
            }

            // Create in-app welcome notification
            $this->createWelcomeNotification($user, $vendor);

            // Send onboarding checklist email
            $this->sendOnboardingChecklist($vendor, $user);

            Log::info('Vendor welcome email sent', [
                'vendor_id' => $vendor->id,
                'vendor_email' => $user->email,
                'company_name' => $vendor->company_name,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send vendor welcome email', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Send welcome email to vendor
     */
    protected function sendWelcomeEmail($vendor, $user): void
    {
        $locale = $user->locale ?? 'en';

        Mail::to($user->email)->send(new VendorWelcomeEmail($vendor, $user, $locale));

        // Also send a copy to support for tracking
        Mail::to(config('mail.support_email'))->send(new VendorWelcomeEmail($vendor, $user, $locale));
    }

    /**
     * Send welcome SMS to vendor
     */
    protected function sendWelcomeSms($user): void
    {
        $message = "Welcome to MADD Commerce! Your vendor account has been approved. Login at " . config('app.url') . "/vendor/login to start selling.";

        try {
            $twilio = new \Twilio\Rest\Client(
                config('services.twilio.sid'),
                config('services.twilio.token')
            );

            $twilio->messages->create($user->phone, [
                'from' => config('services.twilio.phone_number'),
                'body' => $message,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send vendor welcome SMS', [
                'phone' => $user->phone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create in-app welcome notification
     */
    protected function createWelcomeNotification($user, $vendor): void
    {
        // Create notification for vendor
        $user->notifications()->create([
            'type' => 'vendor_welcome',
            'channel' => 'in_app',
            'title' => [
                'en' => 'Welcome to MADD Commerce! 🎉',
                'de' => 'Willkommen bei MADD Commerce! 🎉',
                'fr' => 'Bienvenue sur MADD Commerce! 🎉',
            ],
            'body' => [
                'en' => "Your vendor account for {$vendor->company_name} has been approved. Complete your store setup to start selling!",
                'de' => "Ihr Verkäuferkonto für {$vendor->company_name} wurde genehmigt. Schließen Sie Ihre Shop-Einrichtung ab, um mit dem Verkauf zu beginnen!",
                'fr' => "Votre compte vendeur pour {$vendor->company_name} a été approuvé. Terminez la configuration de votre boutique pour commencer à vendre!",
            ],
            'data' => [
                'vendor_id' => $vendor->id,
                'company_name' => $vendor->company_name,
                'onboarding_step' => $vendor->onboarding_step,
            ],
            'action_url' => '/vendor/onboarding',
            'priority' => 'high',
        ]);

        // Also notify admin team
        $admins = \App\Models\User::role('admin')->get();
        foreach ($admins as $admin) {
            $admin->notifications()->create([
                'type' => 'vendor_approved',
                'channel' => 'in_app',
                'title' => [
                    'en' => 'New Vendor Approved',
                    'de' => 'Neuer Verkäufer genehmigt',
                    'fr' => 'Nouveau vendeur approuvé',
                ],
                'body' => [
                    'en' => "{$vendor->company_name} has been approved and is now active.",
                    'de' => "{$vendor->company_name} wurde genehmigt und ist jetzt aktiv.",
                    'fr' => "{$vendor->company_name} a été approuvé et est maintenant actif.",
                ],
                'data' => [
                    'vendor_id' => $vendor->id,
                    'company_name' => $vendor->company_name,
                ],
                'action_url' => '/admin/vendors/' . $vendor->id,
                'priority' => 'medium',
            ]);
        }
    }

    /**
     * Send onboarding checklist email
     */
    protected function sendOnboardingChecklist($vendor, $user): void
    {
        $checklist = [
            [
                'step' => 1,
                'title' => 'Complete Company Profile',
                'description' => 'Add your company logo, description, and contact information.',
                'completed' => !empty($vendor->logo_url) && !empty($vendor->description),
            ],
            [
                'step' => 2,
                'title' => 'Add Bank Account',
                'description' => 'Add your bank account details to receive payouts.',
                'completed' => $vendor->bankAccounts()->where('is_verified', true)->exists(),
            ],
            [
                'step' => 3,
                'title' => 'Upload First Product',
                'description' => 'Start adding products to your store.',
                'completed' => $vendor->products()->exists(),
            ],
            [
                'step' => 4,
                'title' => 'Configure Shipping',
                'description' => 'Set up shipping methods and rates.',
                'completed' => false, // Would check shipping config
            ],
            [
                'step' => 5,
                'title' => 'Customize Store',
                'description' => 'Choose a theme and customize your store appearance.',
                'completed' => $vendor->stores()->whereNotNull('theme_id')->exists(),
            ],
        ];

        $completedCount = collect($checklist)->where('completed', true)->count();
        $totalSteps = count($checklist);
        $progress = round(($completedCount / $totalSteps) * 100);

        // Send onboarding email
        Mail::to($user->email)->send(new \App\Mail\Vendor\VendorOnboardingChecklist($vendor, $checklist, $progress));
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['vendor_welcome', 'vendor_id:' . ($this->vendor->id ?? 'unknown')];
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('SendWelcomeEmail listener failed', [
            'vendor_id' => $this->vendor->id ?? null,
            'error' => $exception->getMessage(),
        ]);

        // Notify admin about welcome email failure
        \App\Jobs\Notification\SendAdminAlert::dispatch(
            'Vendor Welcome Email Failed',
            'Failed to send welcome email to vendor ID: ' . ($this->vendor->id ?? 'unknown') . '. Error: ' . $exception->getMessage()
        );
    }
}