<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Resources\SettlementResource;
use App\Models\Financial\Settlement;
use App\Models\Financial\Transaction;
use App\Models\Financial\Payout;
use App\Models\Vendor\VendorBankAccount;
use App\Services\Vendor\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorSettlementController extends Controller
{
    protected $settlementService;

    public function __construct(SettlementService $settlementService)
    {
        $this->settlementService = $settlementService;
    }

    /**
     * Get all settlements for the vendor
     */
    public function index(Request $request)
    {
        $vendor = $request->user()->vendor;

        $settlements = Settlement::where('vendor_id', $vendor->getKey())
            ->with(['transactions', 'payout'])
            ->orderBy('period_end', 'desc')
            ->paginate($request->get('per_page', 20));

        // Get summary statistics
        $summary = [
            'total_settled' => Settlement::where('vendor_id', $vendor->getKey())->where('status', 'paid')->sum('net_payout'),
            'total_pending' => Settlement::where('vendor_id', $vendor->getKey())->where('status', 'pending')->sum('net_payout'),
            'total_approved' => Settlement::where('vendor_id', $vendor->getKey())->where('status', 'approved')->sum('net_payout'),
            'last_settlement' => Settlement::where('vendor_id', $vendor->getKey())
                ->where('status', 'paid')
                ->latest('paid_at')
                ->first(),
            'next_estimated' => $this->getNextEstimatedSettlement($vendor),
        ];

        return response()->json([
            'success' => true,
            'data' => SettlementResource::collection($settlements),
            'summary' => $summary,
            'meta' => [
                'current_page' => $settlements->currentPage(),
                'last_page' => $settlements->lastPage(),
                'total' => $settlements->total(),
            ]
        ]);
    }

    /**
     * Get a specific settlement
     */
    public function show(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $settlement = Settlement::where('vendor_id', $vendor->getKey())
            ->with(['transactions', 'payout', 'orders'])
            ->findOrFail($id);

        // Get transaction breakdown
        $breakdown = [
            'sales' => $settlement->transactions()->where('type', 'sale')->sum('amount'),
            'refunds' => $settlement->transactions()->where('type', 'refund')->sum('amount'),
            'commissions' => $settlement->transactions()->where('type', 'commission')->sum('amount'),
            'adjustments' => $settlement->transactions()->where('type', 'adjustment')->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'settlement' => new SettlementResource($settlement),
                'breakdown' => $breakdown,
                'transactions' => $settlement->transactions,
                'orders_included' => $settlement->orders()->count(),
            ]
        ]);
    }

    /**
     * Get settlement summary
     */
    public function summary(Request $request)
    {
        $vendor = $request->user()->vendor;

        $summary = [
            'current_balance' => $vendor->current_balance,
            'pending_balance' => $vendor->pending_balance,
            'total_earned' => $vendor->total_earned,
            'total_paid' => $vendor->total_commission_paid,
            'this_month' => [
                'earned' => Transaction::where('vendor_id', $vendor->getKey())
                    ->where('type', 'sale')
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->sum('amount'),
                'commission' => Transaction::where('vendor_id', $vendor->getKey())
                    ->where('type', 'commission')
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->sum('amount'),
            ],
            'last_settlement' => Settlement::where('vendor_id', $vendor->getKey())
                ->where('status', 'paid')
                ->latest('paid_at')
                ->first(),
            'upcoming_settlement' => $this->settlementService->calculateUpcomingSettlement($vendor),
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }

    /**
     * Get transaction history
     */
    public function transactions(Request $request)
    {
        $vendor = $request->user()->vendor;

        $query = Transaction::where('vendor_id', $vendor->getKey())
            ->with(['order', 'settlement']);

        // Apply filters
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $transactions,
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ]
        ]);
    }

    /**
     * Request manual payout
     */
    public function requestPayout(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:50|max:' . $request->user()->vendor->current_balance,
            'bank_account_id' => 'required_if:payout_method,bank|exists:vendor_bank_accounts,id',
            'payout_method' => 'required|in:bank,paypal,stripe',
        ]);

        $vendor = $request->user()->vendor;

        // Check minimum payout amount
        $minPayout = config('vendor.min_payout_amount', 50);
        if ($request->amount < $minPayout) {
            return response()->json([
                'success' => false,
                'message' => "Minimum payout amount is {$minPayout} {$vendor->currency_code}",
            ], 422);
        }

        // Check sufficient balance
        if ($vendor->current_balance < $request->amount) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance for payout request',
                'available_balance' => $vendor->current_balance,
                'requested_amount' => $request->amount,
            ], 422);
        }

        // Verify bank account if using bank transfer
        if ($request->payout_method === 'bank') {
            $bankAccount = VendorBankAccount::where('id', $request->bank_account_id)
                ->where('vendor_id', $vendor->getKey())
                ->first();

            if (!$bankAccount || !$bankAccount->is_verified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bank account must be verified before requesting payout',
                ], 422);
            }
        }

        try {
            $payout = $this->settlementService->createPayoutRequest($vendor, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Payout request submitted successfully',
                'data' => [
                    'payout_id' => $payout->id,
                    'amount' => $payout->amount,
                    'status' => $payout->status,
                    'estimated_date' => now()->addDays(3)->toDateString(),
                    'processing_time' => '2-3 business days',
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process payout request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payout history
     */
    public function payouts(Request $request)
    {
        $vendor = $request->user()->vendor;

        $payouts = Payout::where('vendor_id', $vendor->getKey())
            ->with('settlement')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        $summary = [
            'total_paid' => Payout::where('vendor_id', $vendor->getKey())->where('status', 'completed')->sum('amount'),
            'total_pending' => Payout::where('vendor_id', $vendor->getKey())->where('status', 'pending')->sum('amount'),
            'total_processing' => Payout::where('vendor_id', $vendor->getKey())->where('status', 'processing')->sum('amount'),
            'last_payout' => Payout::where('vendor_id', $vendor->getKey())->where('status', 'completed')->latest('completed_at')->first(),
        ];

        return response()->json([
            'success' => true,
            'data' => $payouts,
            'summary' => $summary,
            'meta' => [
                'current_page' => $payouts->currentPage(),
                'last_page' => $payouts->lastPage(),
                'total' => $payouts->total(),
            ]
        ]);
    }

    /**
     * Get payout details
     */
    public function showPayout(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $payout = Payout::where('vendor_id', $vendor->getKey())
            ->with('settlement')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $payout
        ]);
    }

    /**
     * Cancel pending payout
     */
    public function cancelPayout(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $payout = Payout::where('vendor_id', $vendor->getKey())
            ->where('status', 'pending')
            ->findOrFail($id);

        DB::beginTransaction();

        try {
            // Refund the reserved amount to vendor balance
            $vendor->updateBalance($payout->amount, 'credit');
            $vendor->decrement('pending_balance', $payout->amount);

            // Update payout status
            $payout->status = 'cancelled';
            $payout->save();

            // Update transaction
            $transaction = Transaction::where('reference', $payout->id)
                ->where('type', 'payout')
                ->first();

            if ($transaction) {
                $transaction->status = 'reversed';
                $transaction->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Payout cancelled successfully',
                'data' => [
                    'refunded_amount' => $payout->amount,
                    'new_balance' => $vendor->current_balance,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel payout',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download settlement statement
     */
    public function downloadStatement(Request $request, $id)
    {
        $vendor = $request->user()->vendor;

        $settlement = Settlement::where('vendor_id', $vendor->getKey())
            ->where('status', 'paid')
            ->findOrFail($id);

        if (!$settlement->statement_pdf_path) {
            return response()->json([
                'success' => false,
                'message' => 'Statement not available for this settlement',
            ], 404);
        }

        // In production, generate signed URL for S3
        return response()->json([
            'success' => true,
            'data' => [
                'download_url' => $settlement->statement_pdf_path,
                'settlement_number' => $settlement->settlement_number,
                'expires_in' => 900, // 15 minutes
            ]
        ]);
    }

    /**
     * Get next estimated settlement date
     */
    private function getNextEstimatedSettlement($vendor)
    {
        $lastSettlement = Settlement::where('vendor_id', $vendor->getKey())
            ->where('status', 'paid')
            ->latest('period_end')
            ->first();

        if ($lastSettlement) {
            $nextStart = $lastSettlement->period_end->copy()->addDay();
            $nextEnd = $nextStart->copy()->endOfMonth();
        } else {
            $nextStart = now()->startOfMonth();
            $nextEnd = now()->endOfMonth();
        }

        return [
            'period_start' => $nextStart->toDateString(),
            'period_end' => $nextEnd->toDateString(),
            'estimated_payout_date' => $nextEnd->addDays(15)->toDateString(),
        ];
    }

    /**
     * Placeholder until transaction export is implemented.
     */
    public function exportTransactions(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Transaction export is not implemented yet.',
        ], 501);
    }
}
