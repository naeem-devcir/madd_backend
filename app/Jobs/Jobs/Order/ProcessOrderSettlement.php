<?php

namespace App\Jobs\Jobs\Order;

use App\Models\Order\Order;
use App\Models\Financial\Transaction;
use App\Services\Vendor\SettlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOrderSettlement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;

    public $tries = 3;
    public $backoff = [60, 300, 600];

    /**
     * Create a new job instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     */
    public function handle(SettlementService $settlementService): void
    {
        DB::beginTransaction();

        try {
            // Calculate commission if not already calculated
            if ($this->order->commission_amount === null) {
                $this->order->calculateCommission();
            }

            // Create transaction record for vendor
            Transaction::create([
                'order_id' => $this->order->uuid,
                'vendor_id' => $this->order->vendor_id,
                'type' => 'sale',
                'amount' => $this->order->vendor_payout_amount,
                'status' => 'completed',
                'currency_code' => $this->order->currency_code,
                'description' => 'Sale from order #' . $this->order->order_number,
                'metadata' => [
                    'order_number' => $this->order->order_number,
                    'customer_email' => $this->order->customer_email,
                ],
            ]);

            // Create commission transaction
            Transaction::create([
                'order_id' => $this->order->uuid,
                'vendor_id' => $this->order->vendor_id,
                'type' => 'commission',
                'amount' => -$this->order->commission_amount,
                'status' => 'completed',
                'currency_code' => $this->order->currency_code,
                'description' => 'Platform commission for order #' . $this->order->order_number,
            ]);

            // Update vendor balance
            $vendor = $this->order->vendor;
            $vendor->updateBalance($this->order->vendor_payout_amount, 'credit');

            // Mark order as settled
            $this->order->settled_at = now();
            $this->order->save();

            DB::commit();

            Log::info('Order settlement processed', [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'vendor_id' => $this->order->vendor_id,
                'payout_amount' => $this->order->vendor_payout_amount,
                'commission' => $this->order->commission_amount,
            ]);

            // Check if vendor is eligible for MLM commission
            if ($vendor->mlm_referrer_id) {
                \App\Jobs\Jobs\Mlm\CalculateMlmCommissions::dispatch(
                    'vendor_sale',
                    $this->order->id,
                    $this->order->subtotal,
                    $this->order->currency_code
                );
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process order settlement', [
                'order_id' => $this->order->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Order settlement job failed', [
            'order_id' => $this->order->id,
            'error' => $exception->getMessage(),
        ]);

        // Notify admin about failed settlement
        \App\Jobs\Jobs\Notification\SendAdminAlert::dispatch(
            'Order Settlement Failed',
            'Failed to process settlement for order #' . $this->order->order_number . '. Error: ' . $exception->getMessage()
        );
    }
}
