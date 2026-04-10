<?php

namespace App\Jobs\Jobs\Mlm;

use App\Models\Mlm\MlmAgent;
use App\Models\Mlm\MlmCommission;
use App\Models\Vendor\Vendor;
use App\Models\Order\Order;
use App\Services\Mlm\CommissionCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateMlmCommissions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $sourceType;
    protected $sourceId;
    protected $amount;
    protected $currencyCode;

    public $tries = 3;
    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     *
     * @param string $sourceType (vendor_signup, vendor_sale)
     * @param string $sourceId
     * @param float $amount
     * @param string $currencyCode
     */
    public function __construct(string $sourceType, string $sourceId, float $amount, string $currencyCode = 'EUR')
    {
        $this->sourceType = $sourceType;
        $this->sourceId = $sourceId;
        $this->amount = $amount;
        $this->currencyCode = $currencyCode;
    }

    /**
     * Execute the job.
     */
    public function handle(CommissionCalculator $calculator): void
    {
        DB::beginTransaction();

        try {
            // Get the source based on type
            $source = $this->getSource();
            
            if (!$source) {
                throw new \Exception('Source not found for type: ' . $this->sourceType);
            }

            // Get the referrer/agent
            $referrerId = match($this->sourceType) {
                'vendor_signup' => $source->mlm_referrer_id,
                'vendor_sale' => $source->vendor->mlm_referrer_id,
                default => null,
            };

            if (!$referrerId) {
                Log::info('No MLM referrer found', [
                    'source_type' => $this->sourceType,
                    'source_id' => $this->sourceId,
                ]);
                DB::commit();
                return;
            }

            // Get MLM agent
            $agent = MlmAgent::where('user_id', $referrerId)->first();

            if (!$agent || $agent->status !== 'active') {
                Log::info('MLM agent not active or not found', [
                    'user_id' => $referrerId,
                    'agent_status' => $agent?->status,
                ]);
                DB::commit();
                return;
            }

            // Calculate commissions for all levels
            $commissions = $calculator->calculateCommissions($agent, $this->amount, $this->sourceType);

            // Create commission records
            foreach ($commissions as $level => $commissionData) {
                if ($commissionData['amount'] > 0) {
                    MlmCommission::create([
                        'agent_id' => $commissionData['agent_id'],
                        'source_type' => $this->sourceType,
                        'source_id' => $this->sourceId,
                        'level' => $level,
                        'amount' => $commissionData['amount'],
                        'currency_code' => $this->currencyCode,
                        'status' => 'pending',
                        'description' => $this->getDescription($level, $this->sourceType),
                        'calculation_snapshot' => [
                            'base_amount' => $this->amount,
                            'commission_rate' => $commissionData['rate'],
                            'level' => $level,
                            'calculated_at' => now()->toIso8601String(),
                        ],
                    ]);
                }
            }

            DB::commit();

            Log::info('MLM commissions calculated', [
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
                'amount' => $this->amount,
                'commissions_count' => count($commissions),
                'total_commission' => array_sum(array_column($commissions, 'amount')),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to calculate MLM commissions', [
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }

    /**
     * Get the source model.
     */
    protected function getSource()
    {
        return match($this->sourceType) {
            'vendor_signup' => Vendor::find($this->sourceId),
            'vendor_sale' => Order::find($this->sourceId),
            default => null,
        };
    }

    /**
     * Get description for commission record.
     */
    protected function getDescription(int $level, string $sourceType): string
    {
        return match($sourceType) {
            'vendor_signup' => "Level {$level} commission for vendor signup",
            'vendor_sale' => "Level {$level} commission from sale",
            default => "Level {$level} MLM commission",
        };
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('MLM commission calculation job failed', [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'error' => $exception->getMessage(),
        ]);

        // Notify admin
        \App\Jobs\Jobs\Notification\SendAdminAlert::dispatch(
            'MLM Commission Calculation Failed',
            'Failed to calculate MLM commissions for ' . $this->sourceType . ' ID: ' . $this->sourceId . '. Error: ' . $exception->getMessage()
        );
    }
}