<?php

namespace App\Jobs\Jobs\Product;

use App\Models\Product\ProductApproval;
use App\Models\Product\ProductDraft;
use App\Services\Product\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessProductApproval implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $approvalId;

    public $tries = 2;
    public $backoff = [60, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(string $approvalId)
    {
        $this->approvalId = $approvalId;
    }

    /**
     * Execute the job.
     */
    public function handle(ProductService $productService): void
    {
        try {
            $approval = ProductApproval::with(['draft', 'draft.vendor'])->findOrFail($this->approvalId);

            if ($approval->status !== 'pending') {
                Log::info('Product approval already processed', [
                    'approval_id' => $this->approvalId,
                    'status' => $approval->status,
                ]);
                return;
            }

            $draft = $approval->draft;

            // Auto-approve if vendor is trusted and product meets criteria
            if ($this->shouldAutoApprove($draft)) {
                $productService->approveProduct($draft, 'system');
                
                Log::info('Product auto-approved', [
                    'draft_id' => $draft->id,
                    'vendor_id' => $draft->vendor_id,
                    'product_name' => $draft->name,
                ]);
            } else {
                // Send notification to admin for manual review
                $this->notifyAdmins($draft);
                
                Log::info('Product pending manual review', [
                    'draft_id' => $draft->id,
                    'vendor_id' => $draft->vendor_id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to process product approval', [
                'approval_id' => $this->approvalId,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }

    /**
     * Check if product should be auto-approved.
     */
    protected function shouldAutoApprove(ProductDraft $draft): bool
    {
        $vendor = $draft->vendor;

        // Auto-approve conditions
        return $vendor->kyc_status === 'verified' 
            && $vendor->status === 'active'
            && $vendor->total_products_approved > 10
            && $vendor->rating_average >= 4.0
            && $draft->auto_approve;
    }

    /**
     * Notify admins about pending product approval.
     */
    protected function notifyAdmins(ProductDraft $draft): void
    {
        $admins = \App\Models\User::role('admin')->get();

        foreach ($admins as $admin) {
            \App\Jobs\Jobs\Notification\SendAdminAlert::dispatch(
                'Product Pending Approval',
                'Product "' . $draft->name . '" from vendor "' . $draft->vendor->company_name . '" requires approval.',
                [
                    'draft_id' => $draft->id,
                    'vendor_id' => $draft->vendor_id,
                    'product_name' => $draft->name,
                    'sku' => $draft->sku,
                ]
            );
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Product approval job failed', [
            'approval_id' => $this->approvalId,
            'error' => $exception->getMessage(),
        ]);
    }
}