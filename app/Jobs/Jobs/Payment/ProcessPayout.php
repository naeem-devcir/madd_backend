<?php

namespace App\Jobs\Jobs\Payment;

use App\Models\Financial\Payout;
use App\Services\Payment\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payout;

    public $tries = 3;
    public $backoff = [300, 600, 1200]; // 5 min, 10 min, 20 min
    public $timeout = 120; // 2 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(Payout $payout)
    {
        $this->payout = $payout;
    }

    /**
     * Execute the job.
     */
    public function handle(PaymentService $paymentService): void
    {
        try {
            // Mark as processing
            $this->payout->markAsProcessing();

            // Process based on payout method
            $result = match($this->payout->payout_method) {
                'stripe' => $paymentService->processStripePayout($this->payout),
                'paypal' => $paymentService->processPayPalPayout($this->payout),
                'bank_transfer' => $paymentService->processBankPayout($this->payout),
                default => throw new \Exception('Unsupported payout method: ' . $this->payout->payout_method),
            };

            if ($result['success']) {
                $this->payout->markAsCompleted($result['transaction_id']);
                
                Log::info('Payout processed successfully', [
                    'payout_id' => $this->payout->id,
                    'vendor_id' => $this->payout->vendor_id,
                    'amount' => $this->payout->amount,
                    'method' => $this->payout->payout_method,
                    'transaction_id' => $result['transaction_id'],
                ]);
            } else {
                throw new \Exception($result['error'] ?? 'Payout processing failed');
            }

        } catch (\Exception $e) {
            Log::error('Failed to process payout', [
                'payout_id' => $this->payout->id,
                'error' => $e->getMessage(),
            ]);

            $this->payout->markAsFailed($e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Payout job failed after retries', [
            'payout_id' => $this->payout->id,
            'vendor_id' => $this->payout->vendor_id,
            'amount' => $this->payout->amount,
            'error' => $exception->getMessage(),
        ]);

        // Notify admin about failed payout
        \App\Jobs\Jobs\Notification\SendAdminAlert::dispatch(
            'Payout Failed',
            'Failed to process payout #' . $this->payout->id . ' for vendor ID: ' . $this->payout->vendor_id . '. Amount: ' . $this->payout->amount . ' ' . $this->payout->currency . '. Error: ' . $exception->getMessage()
        );

        // Notify vendor
        $vendor = $this->payout->vendor;
        if ($vendor && $vendor->user) {
            \App\Jobs\Jobs\Notification\SendVendorNotification::dispatch(
                $vendor->user,
                'payout_failed',
                'Your payout of ' . $this->payout->currency . ' ' . number_format($this->payout->amount, 2) . ' could not be processed. Please check your payout details and contact support.'
            );
        }
    }
}