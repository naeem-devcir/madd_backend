<?php

namespace App\Jobs\Jobs\Settlement;

use App\Models\Vendor\Vendor;
use App\Models\Financial\Settlement;
use App\Services\Vendor\SettlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CalculateVendorSettlement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $vendorId;
    protected $periodStart;
    protected $periodEnd;

    public $tries = 2;
    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     *
     * @param string $vendorId
     * @param string|null $periodStart (Y-m-d)
     * @param string|null $periodEnd (Y-m-d)
     */
    public function __construct(string $vendorId, ?string $periodStart = null, ?string $periodEnd = null)
    {
        $this->vendorId = $vendorId;
        
        // Default to previous month if not specified
        $this->periodStart = $periodStart ?? now()->subMonth()->startOfMonth()->toDateString();
        $this->periodEnd = $periodEnd ?? now()->subMonth()->endOfMonth()->toDateString();
    }

    /**
     * Execute the job.
     */
    public function handle(SettlementService $settlementService): void
    {
        DB::beginTransaction();

        try {
            $vendor = Vendor::findOrFail($this->vendorId);
            $startDate = \Carbon\Carbon::parse($this->periodStart);
            $endDate = \Carbon\Carbon::parse($this->periodEnd);

            // Check if settlement already exists for this period
            $existingSettlement = Settlement::where('vendor_id', $vendor->getKey())
                ->where('period_start', $startDate)
                ->where('period_end', $endDate)
                ->first();

            if ($existingSettlement) {
                Log::info('Settlement already exists for period', [
                    'vendor_id' => $vendor->getKey(),
                    'period_start' => $this->periodStart,
                    'period_end' => $this->periodEnd,
                ]);
                DB::commit();
                return;
            }

            // Calculate settlement
            $settlement = $settlementService->calculateSettlement($vendor, $startDate, $endDate);

            DB::commit();

            Log::info('Vendor settlement calculated', [
                'vendor_id' => $vendor->getKey(),
                'settlement_id' => $settlement->id,
                'period_start' => $this->periodStart,
                'period_end' => $this->periodEnd,
                'net_payout' => $settlement->net_payout,
            ]);

            // Send notification to vendor
            \App\Jobs\Jobs\Notification\SendSettlementNotification::dispatch($settlement, 'generated');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to calculate vendor settlement', [
                'vendor_id' => $this->vendorId,
                'period_start' => $this->periodStart,
                'period_end' => $this->periodEnd,
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
        Log::critical('Vendor settlement calculation failed', [
            'vendor_id' => $this->vendorId,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'error' => $exception->getMessage(),
        ]);

        // Notify admin
        \App\Jobs\Jobs\Notification\SendAdminAlert::dispatch(
            'Settlement Calculation Failed',
            'Failed to calculate settlement for vendor ID: ' . $this->vendorId . ' for period ' . $this->periodStart . ' to ' . $this->periodEnd . '. Error: ' . $exception->getMessage()
        );
    }
}
