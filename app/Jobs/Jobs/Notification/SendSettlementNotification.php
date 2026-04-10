<?php

namespace App\Jobs\Jobs\Notification;

use App\Models\Financial\Settlement;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSettlementNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $settlement;
    protected $eventType;

    public $tries = 3;
    public $backoff = [60, 120, 300];

    /**
     * Create a new job instance.
     *
     * @param Settlement $settlement
     * @param string $eventType (generated, approved, paid, disputed)
     */
    public function __construct(Settlement $settlement, string $eventType)
    {
        $this->settlement = $settlement;
        $this->eventType = $eventType;
    }

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            $vendor = $this->settlement->vendor->user;

            if (!$vendor) {
                Log::warning('Vendor user not found for settlement notification', [
                    'settlement_id' => $this->settlement->id,
                    'vendor_id' => $this->settlement->vendor_id,
                ]);
                return;
            }

            $data = $this->getNotificationData();
            $notificationService->send($vendor, $data);

            // Also notify admin for certain events
            if (in_array($this->eventType, ['disputed', 'paid'])) {
                $this->notifyAdmin($notificationService);
            }

            Log::info('Settlement notification sent', [
                'settlement_id' => $this->settlement->id,
                'vendor_id' => $this->settlement->vendor_id,
                'event_type' => $this->eventType,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send settlement notification', [
                'settlement_id' => $this->settlement->id,
                'event_type' => $this->eventType,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Get notification data based on event type.
     */
    protected function getNotificationData(): array
    {
        $titles = [
            'generated' => 'New Settlement Available',
            'approved' => 'Settlement Approved',
            'paid' => 'Payment Received! 💰',
            'disputed' => 'Settlement Disputed ⚠️',
        ];

        $bodies = [
            'generated' => "Your settlement for period {$this->settlement->period_start->format('M d, Y')} - {$this->settlement->period_end->format('M d, Y')} is ready for review. Amount: {$this->settlement->currency_code} " . number_format($this->settlement->net_payout, 2),
            'approved' => "Your settlement has been approved and will be processed shortly.",
            'paid' => "Payment of {$this->settlement->currency_code} " . number_format($this->settlement->net_payout, 2) . " has been sent to your {$this->settlement->payment_method} account.",
            'disputed' => "Your settlement has been disputed. Please check your dashboard for details and contact support.",
        ];

        $priorities = [
            'generated' => 'medium',
            'approved' => 'medium',
            'paid' => 'high',
            'disputed' => 'urgent',
        ];

        return [
            'type' => 'settlement_' . $this->eventType,
            'title' => $titles[$this->eventType] ?? 'Settlement Update',
            'body' => $bodies[$this->eventType] ?? 'Your settlement has been updated.',
            'priority' => $priorities[$this->eventType] ?? 'medium',
            'data' => [
                'settlement_id' => $this->settlement->id,
                'settlement_number' => $this->settlement->settlement_number,
                'period_start' => $this->settlement->period_start->toIso8601String(),
                'period_end' => $this->settlement->period_end->toIso8601String(),
                'amount' => $this->settlement->net_payout,
                'currency' => $this->settlement->currency_code,
                'status' => $this->settlement->status,
                'event_type' => $this->eventType,
            ],
            'channels' => ['email', 'in_app'],
        ];
    }

    /**
     * Notify admin about settlement events.
     */
    protected function notifyAdmin(NotificationService $service): void
    {
        $admins = User::role('admin')->get();

        foreach ($admins as $admin) {
            $service->send($admin, [
                'type' => 'admin_settlement_' . $this->eventType,
                'title' => 'Settlement ' . ucfirst($this->eventType),
                'body' => "Settlement #{$this->settlement->settlement_number} for vendor {$this->settlement->vendor->company_name} has been {$this->eventType}. Amount: {$this->settlement->currency_code} " . number_format($this->settlement->net_payout, 2),
                'priority' => $this->eventType === 'disputed' ? 'urgent' : 'medium',
                'data' => [
                    'settlement_id' => $this->settlement->id,
                    'vendor_id' => $this->settlement->vendor_id,
                    'vendor_name' => $this->settlement->vendor->company_name,
                    'amount' => $this->settlement->net_payout,
                ],
                'channels' => ['email', 'in_app'],
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Settlement notification job failed', [
            'settlement_id' => $this->settlement->id,
            'event_type' => $this->eventType,
            'error' => $exception->getMessage(),
        ]);
    }
}