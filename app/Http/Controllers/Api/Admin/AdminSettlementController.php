<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Financial\Settlement;
use App\Models\Financial\Payout;
use App\Models\Vendor\Vendor;
use App\Services\Vendor\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminSettlementController extends Controller
{
    protected $settlementService;

    public function __construct(SettlementService $settlementService)
    {
        $this->settlementService = $settlementService;
    }

    /**
     * Get all settlements with filters
     */
    public function index(Request $request)
    {
        $query = Settlement::with(['vendor', 'vendor.user', 'approvedBy', 'payout']);

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        if ($request->has('period_start')) {
            $query->where('period_start', '>=', $request->period_start);
        }

        if ($request->has('period_end')) {
            $query->where('period_end', '<=', $request->period_end);
        }

        if ($request->has('search')) {
            $query->whereHas('vendor', function($q) use ($request) {
                $q->where('company_name', 'like', '%' . $request->search . '%')
                  ->orWhere('company_slug', 'like', '%' . $request->search . '%');
            });
        }

        $settlements = $query->orderBy('period_end', 'desc')
            ->paginate($request->get('per_page', 20));

        // Add summary statistics
        $summary = [
            'total_pending' => Settlement::where('status', 'pending')->sum('net_payout'),
            'total_approved' => Settlement::where('status', 'approved')->sum('net_payout'),
            'total_paid' => Settlement::where('status', 'paid')->sum('net_payout'),
            'total_disputed' => Settlement::where('status', 'disputed')->sum('net_payout'),
            'pending_count' => Settlement::where('status', 'pending')->count(),
            'approved_count' => Settlement::where('status', 'approved')->count(),
            'paid_count' => Settlement::where('status', 'paid')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $settlements,
            'summary' => $summary,
            'meta' => [
                'current_page' => $settlements->currentPage(),
                'last_page' => $settlements->lastPage(),
                'total' => $settlements->total(),
            ]
        ]);
    }

    /**
     * Get single settlement
     */
    public function show($id)
    {
        $settlement = Settlement::with([
            'vendor', 
            'vendor.user', 
            'transactions', 
            'transactions.order',
            'orders',
            'payout',
            'approvedBy',
            'maddCompany'
        ])->findOrFail($id);

        // Get transaction summary
        $transactionSummary = [
            'sales' => $settlement->transactions()->where('type', 'sale')->sum('amount'),
            'refunds' => $settlement->transactions()->where('type', 'refund')->sum('amount'),
            'commissions' => $settlement->transactions()->where('type', 'commission')->sum('amount'),
            'adjustments' => $settlement->transactions()->where('type', 'adjustment')->sum('amount'),
            'fees' => $settlement->transactions()->where('type', 'fee')->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'settlement' => $settlement,
                'transaction_summary' => $transactionSummary,
                'orders_count' => $settlement->orders()->count(),
                'total_orders_value' => $settlement->orders()->sum('grand_total'),
            ]
        ]);
    }

    /**
     * Generate settlements for vendors
     */
    public function generate(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'vendor_id' => 'nullable|exists:vendors,uuid',
        ]);

        $periodStart = $request->period_start;
        $periodEnd = $request->period_end;

        $vendors = Vendor::where('status', 'active');

        if ($request->has('vendor_id')) {
            $vendors->whereKey($request->vendor_id);
        }

        $vendors = $vendors->get();

        $generated = [];
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($vendors as $vendor) {
                // Check if settlement already exists for this period
                $existing = Settlement::where('vendor_id', $vendor->getKey())
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd)
                    ->first();

                if ($existing) {
                    $errors[] = "Settlement for {$vendor->company_name} already exists for this period";
                    continue;
                }

                $settlement = $this->settlementService->calculateSettlement(
                    $vendor,
                    $periodStart,
                    $periodEnd
                );

                $generated[] = [
                    'vendor' => $vendor->company_name,
                    'settlement_id' => $settlement->id,
                    'amount' => $settlement->net_payout,
                ];
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Settlements generated successfully',
                'data' => [
                    'generated' => $generated,
                    'generated_count' => count($generated),
                    'errors' => $errors,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate settlements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve settlement
     */
    public function approve(Request $request, $id)
    {
        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $settlement = Settlement::findOrFail($id);

        if ($settlement->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Settlement is not pending approval'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $settlement->approve(auth()->user());

            if ($request->notes) {
                $settlement->notes = $request->notes;
                $settlement->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Settlement approved successfully',
                'data' => $settlement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve settlement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark settlement as paid
     */
    public function markAsPaid(Request $request, $id)
    {
        $request->validate([
            'payment_reference' => 'required|string',
            'payment_method' => 'required|in:bank_transfer,paypal,stripe,manual',
            'notes' => 'nullable|string',
        ]);

        $settlement = Settlement::findOrFail($id);

        if ($settlement->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Settlement must be approved before marking as paid'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $settlement->markAsPaid(
                $request->payment_reference,
                $request->payment_method
            );

            if ($request->notes) {
                $settlement->notes = ($settlement->notes ? $settlement->notes . "\n" : '') . $request->notes;
                $settlement->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Settlement marked as paid successfully',
                'data' => $settlement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark settlement as paid',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark settlement as disputed
     */
    public function markAsDisputed(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string',
        ]);

        $settlement = Settlement::findOrFail($id);

        DB::beginTransaction();

        try {
            $settlement->markAsDisputed($request->reason);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Settlement marked as disputed',
                'data' => $settlement
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark settlement as disputed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recalculate settlement
     */
    public function recalculate($id)
    {
        $settlement = Settlement::findOrFail($id);

        if ($settlement->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot recalculate a paid settlement'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $oldAmount = $settlement->net_payout;
            $settlement->recalculate();
            $newAmount = $settlement->net_payout;

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Settlement recalculated successfully',
                'data' => [
                    'settlement_id' => $settlement->id,
                    'old_amount' => $oldAmount,
                    'new_amount' => $newAmount,
                    'difference' => $newAmount - $oldAmount,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to recalculate settlement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download settlement statement
     */
    public function downloadStatement($id)
    {
        $settlement = Settlement::findOrFail($id);

        if (!$settlement->statement_pdf_path) {
            // Generate statement if not exists
            \App\Jobs\Settlement\GenerateSettlementStatement::dispatchSync($settlement);
            $settlement->refresh();
        }

        if (!$settlement->statement_pdf_path) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate settlement statement'
            ], 500);
        }

        $url = \Storage::disk('s3')->temporaryUrl(
            $settlement->statement_pdf_path,
            now()->addMinutes(15)
        );

        return response()->json([
            'success' => true,
            'data' => [
                'download_url' => $url,
                'filename' => 'settlement_' . $settlement->settlement_number . '.pdf',
            ]
        ]);
    }

    /**
     * Get settlement statistics
     */
    public function statistics(Request $request)
    {
        $period = $request->get('period', 'month');
        $startDate = match($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subQuarter(),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };

        $stats = [
            'total_settled' => Settlement::where('status', 'paid')->sum('net_payout'),
            'total_pending' => Settlement::where('status', 'pending')->sum('net_payout'),
            'total_approved' => Settlement::where('status', 'approved')->sum('net_payout'),
            'average_settlement' => Settlement::where('status', 'paid')->avg('net_payout'),
            'by_month' => Settlement::where('created_at', '>=', $startDate)
                ->select(
                    DB::raw("DATE_FORMAT(period_end, '%Y-%m') as month"),
                    DB::raw('SUM(net_payout) as total'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get(),
            'by_vendor' => Settlement::where('status', 'paid')
                ->select('vendor_id', DB::raw('SUM(net_payout) as total'))
                ->with('vendor')
                ->groupBy('vendor_id')
                ->orderBy('total', 'desc')
                ->limit(10)
                ->get(),
            'payment_methods' => Payout::select('payout_method', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
                ->where('status', 'completed')
                ->groupBy('payout_method')
                ->get(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
            'meta' => [
                'period' => $period,
                'start_date' => $startDate->toDateString(),
            ]
        ]);
    }

    /**
     * Export settlements to CSV
     */
    public function export(Request $request)
    {
        $query = Settlement::with(['vendor']);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('date_from')) {
            $query->whereDate('period_end', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->whereDate('period_end', '<=', $request->date_to);
        }

        $settlements = $query->get();

        $filename = 'settlements_export_' . date('Y-m-d_His') . '.csv';
        $handle = fopen('php://temp', 'w');

        // Headers
        fputcsv($handle, [
            'Settlement ID', 'Vendor', 'Period Start', 'Period End',
            'Gross Sales', 'Refunds', 'Commissions', 'Shipping Fees',
            'Tax Collected', 'Gateway Fees', 'Adjustments', 'Net Payout',
            'Currency', 'Status', 'Payment Method', 'Payment Reference',
            'Approved By', 'Approved At', 'Paid At', 'Created At'
        ]);

        // Data
        foreach ($settlements as $settlement) {
            fputcsv($handle, [
                $settlement->settlement_number,
                $settlement->vendor?->company_name,
                $settlement->period_start,
                $settlement->period_end,
                $settlement->gross_sales,
                $settlement->total_refunds,
                $settlement->total_commissions,
                $settlement->total_shipping_fees,
                $settlement->total_tax_collected,
                $settlement->gateway_fees,
                $settlement->adjustment_amount,
                $settlement->net_payout,
                $settlement->currency_code,
                $settlement->status,
                $settlement->payment_method,
                $settlement->payment_reference,
                $settlement->approvedBy?->full_name,
                $settlement->approved_at,
                $settlement->paid_at,
                $settlement->created_at,
            ]);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $filename,
                'content' => base64_encode($csvContent),
                'mime_type' => 'text/csv',
                'row_count' => $settlements->count(),
            ]
        ]);
    }

    /**
     * Placeholder until pending settlement review is implemented.
     */
    public function pending(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Pending settlements view is not implemented yet.',
        ], 501);
    }

    /**
     * Placeholder until settlement disputes are implemented.
     */
    public function dispute(Request $request, $id)
    {
        return response()->json([
            'success' => false,
            'message' => 'Settlement dispute handling is not implemented yet.',
            'settlement_id' => $id,
        ], 501);
    }
}
