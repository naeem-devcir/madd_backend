<?php

namespace App\Listeners\Payment;

use App\Events\Payment\PaymentReceived;
use App\Services\Vendor\CommissionService;
use App\Services\Mlm\MlmCommissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessCommission implements ShouldQueue
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
     * Commission service instance
     */
    protected $commissionService;

    /**
     * MLM commission service instance
     */
    protected $mlmCommissionService;

    /**
     * Create the event listener.
     */
    public function __construct(
        CommissionService $commissionService,
        MlmCommissionService $mlmCommissionService
    ) {
        $this->commissionService = $commissionService;
        $this->mlmCommissionService = $mlmCommissionService;
    }

    /**
     * Handle the event.
     */
    public function handle(PaymentReceived $event): void
    {
        $order = $event->order;
        $paymentTransaction = $event->paymentTransaction;

        DB::beginTransaction();

        try {
            // Calculate and store platform commission
            $commissionAmount = $this->commissionService->calculateOrderCommission($order);
            
            // Store commission record
            $commission = $this->commissionService->storeCommission($order, $commissionAmount);

            // Calculate MLM commissions if applicable
            if ($order->vendor->mlm_referrer_id) {
                $this->processMlmCommissions($order);
            }

            // Update vendor balance (available after settlement period)
            $this->updateVendorBalance($order, $commissionAmount);

            DB::commit();

            Log::info('Commission processed for order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'commission_amount' => $commissionAmount,
                'vendor_id' => $order->vendor_id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to process commission for order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Process MLM commissions for the order
     */
    protected function processMlmCommissions(Order $order): void
    {
        $vendor = $order->vendor;
        $referrerId = $vendor->mlm_referrer_id;

        // Find the MLM agent who referred this vendor
        $mlmAgent = \App\Models\Mlm\MlmAgent::where('user_id', $referrerId)->first();

        if (!$mlmAgent) {
            Log::warning('MLM agent not found for referrer', ['referrer_id' => $referrerId]);
            return;
        }

        // Calculate commission for each level in the hierarchy
        $currentAgent = $mlmAgent;
        $level = 1;

        while ($currentAgent && $level <= 5) {
            $commissionRate = $this->getCommissionRateForLevel($level);
            $commissionAmount = $order->subtotal * ($commissionRate / 100);

            if ($commissionAmount > 0) {
                $this->mlmCommissionService->createCommission([
                    'agent_id' => $currentAgent->uuid,
                    'source_type' => 'vendor_sale',
                    'source_id' => $order->id,
                    'level' => $level,
                    'amount' => $commissionAmount,
                    'currency_code' => $order->currency_code,
                    'status' => 'pending',
                    'description' => "Commission for order #{$order->order_number} from referred vendor {$vendor->company_name}",
                ]);

                Log::info('MLM commission created', [
                    'level' => $level,
                    'agent_id' => $currentAgent->id,
                    'amount' => $commissionAmount,
                    'order_id' => $order->id,
                ]);
            }

            // Move to parent agent for next level
            $currentAgent = $currentAgent->parent;
            $level++;
        }
    }

    /**
     * Get commission rate for MLM level
     */
    protected function getCommissionRateForLevel(int $level): float
    {
        $rates = [
            1 => 5.00,  // Level 1: 5%
            2 => 3.00,  // Level 2: 3%
            3 => 2.00,  // Level 3: 2%
            4 => 1.00,  // Level 4: 1%
            5 => 0.50,  // Level 5: 0.5%
        ];

        return $rates[$level] ?? 0;
    }

    /**
     * Update vendor balance (add to pending balance until settlement)
     */
    protected function updateVendorBalance(Order $order, float $commissionAmount): void
    {
        $vendor = $order->vendor;
        $vendorPayout = $order->grand_total - $commissionAmount - ($order->payment_fee ?? 0);

        // Add to pending balance (will be settled in next settlement cycle)
        $vendor->increment('pending_balance', $vendorPayout);

        // Update wallet pending balance
        $wallet = $vendor->wallet ?? \App\Models\Vendor\VendorWallet::create(['vendor_id' => $vendor->getKey()]);
        $wallet->increment('pending_balance', $vendorPayout);

        // Store the payout amount in order for reference
        $order->vendor_payout_amount = $vendorPayout;
        $order->save();
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessCommission listener failed', [
            'order_id' => $this->order->id ?? null,
            'error' => $exception->getMessage(),
        ]);

        // Notify finance team about commission processing failure
        \App\Jobs\Notification\SendFinanceAlert::dispatch(
            'Commission Processing Failed',
            'Failed to process commission for order ID: ' . ($this->order->id ?? 'unknown'),
            'high'
        );
    }
}
