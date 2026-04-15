<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Financial\Settlement;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Report\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Platform performance report
     */
    public function platform(Request $request)
    {
        $request->validate([
            'period' => 'sometimes|in:day,week,month,quarter,year',
            'date_from' => 'required_without:period|date',
            'date_to' => 'required_without:period|date|after_or_equal:date_from',
        ]);

        $period = $request->get('period', 'month');
        $dateFrom = $request->get('date_from') ?? now()->startOfMonth();
        $dateTo = $request->get('date_to') ?? now()->endOfMonth();

        $stats = $this->reportService->getPlatformPerformance($dateFrom, $dateTo);

        return response()->json([
            'success' => true,
            'data' => $stats,
            'meta' => [
                'period' => $period,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Financial report
     */
    public function financial(Request $request)
    {
        $request->validate([
            'period' => 'sometimes|in:day,week,month,quarter,year',
            'date_from' => 'required_without:period|date',
            'date_to' => 'required_without:period|date|after_or_equal:date_from',
        ]);

        $dateFrom = $request->get('date_from') ?? now()->startOfMonth();
        $dateTo = $request->get('date_to') ?? now()->endOfMonth();

        // Revenue breakdown
        $revenue = [
            'total' => Order::whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'cancelled')
                ->sum('grand_total'),
            'by_payment_method' => Order::whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'cancelled')
                ->select('payment_method', DB::raw('SUM(grand_total) as total'), DB::raw('COUNT(*) as count'))
                ->groupBy('payment_method')
                ->get(),
            'by_currency' => Order::whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'cancelled')
                ->select('currency_code', DB::raw('SUM(grand_total) as total'))
                ->groupBy('currency_code')
                ->get(),
        ];

        // Commission collected
        $commission = [
            'total' => Order::whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'cancelled')
                ->sum('commission_amount'),
            'by_vendor' => Order::whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'cancelled')
                ->select('vendor_id', DB::raw('SUM(commission_amount) as total'))
                ->with('vendor')
                ->groupBy('vendor_id')
                ->orderBy('total', 'desc')
                ->limit(20)
                ->get(),
        ];

        // Settlements
        $settlements = [
            'total_paid' => Settlement::whereBetween('paid_at', [$dateFrom, $dateTo])
                ->where('status', 'paid')
                ->sum('net_payout'),
            'total_pending' => Settlement::where('status', 'pending')->sum('net_payout'),
            'total_approved' => Settlement::where('status', 'approved')->sum('net_payout'),
            'by_vendor' => Settlement::whereBetween('created_at', [$dateFrom, $dateTo])
                ->select('vendor_id', DB::raw('SUM(net_payout) as total'), DB::raw('COUNT(*) as count'))
                ->with('vendor')
                ->groupBy('vendor_id')
                ->orderBy('total', 'desc')
                ->limit(20)
                ->get(),
        ];

        // Gateway fees
        $gatewayFees = [
            'total' => Order::whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'cancelled')
                ->sum('payment_fee'),
            'by_gateway' => Order::whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'cancelled')
                ->select('payment_method', DB::raw('SUM(payment_fee) as total'))
                ->groupBy('payment_method')
                ->get(),
        ];

        // Refunds
        $refunds = [
            'total' => DB::table('refunds')
                ->join('orders', 'refunds.order_id', '=', 'orders.uuid')
                ->whereBetween('refunds.created_at', [$dateFrom, $dateTo])
                ->where('refunds.status', 'processed')
                ->sum('refunds.refund_amount'),
            'count' => DB::table('refunds')
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', 'processed')
                ->count(),
            'by_reason' => DB::table('refunds')
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->select('reason', DB::raw('COUNT(*) as count'), DB::raw('SUM(refund_amount) as total'))
                ->groupBy('reason')
                ->get(),
        ];

        // Net profit calculation
        $netProfit = $revenue['total'] - $commission['total'] - $gatewayFees['total'] - $refunds['total'];

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
                'revenue' => $revenue,
                'commission' => $commission,
                'settlements' => $settlements,
                'gateway_fees' => $gatewayFees,
                'refunds' => $refunds,
                'net_profit' => $netProfit,
            ],
        ]);
    }

    /**
     * Vendor performance report
     */
    public function vendorPerformance(Request $request, $vendorId = null)
    {
        $request->validate([
            'period' => 'sometimes|in:day,week,month,quarter,year',
            'date_from' => 'required_without:period|date',
            'date_to' => 'required_without:period|date|after_or_equal:date_from',
        ]);

        $dateFrom = $request->get('date_from') ?? now()->startOfMonth();
        $dateTo = $request->get('date_to') ?? now()->endOfMonth();

        $query = Vendor::with(['user']);

        if ($vendorId) {
            $query->whereKey($vendorId);
        }

        $vendors = $query->get();

        $performance = [];

        foreach ($vendors as $vendor) {
            $orders = Order::where('vendor_id', $vendor->getKey())
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', '!=', 'cancelled');

            $performance[] = [
                'vendor' => [
                    'id' => $vendor->getKey(),
                    'company_name' => $vendor->company_name,
                    'email' => $vendor->user->email,
                ],
                'revenue' => $orders->sum('grand_total'),
                'order_count' => $orders->count(),
                'average_order_value' => $orders->avg('grand_total'),
                'commission' => $orders->sum('commission_amount'),
                'products_sold' => DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->where('orders.vendor_id', $vendor->getKey())
                    ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
                    ->sum('order_items.qty_ordered'),
                'refund_amount' => DB::table('refunds')
                    ->join('orders', 'refunds.order_id', '=', 'orders.uuid')
                    ->where('orders.vendor_id', $vendor->getKey())
                    ->whereBetween('refunds.created_at', [$dateFrom, $dateTo])
                    ->where('refunds.status', 'processed')
                    ->sum('refunds.refund_amount'),
                'settlement_paid' => Settlement::where('vendor_id', $vendor->getKey())
                    ->whereBetween('paid_at', [$dateFrom, $dateTo])
                    ->where('status', 'paid')
                    ->sum('net_payout'),
            ];
        }

        // Sort by revenue
        usort($performance, function ($a, $b) {
            return $b['revenue'] <=> $a['revenue'];
        });

        return response()->json([
            'success' => true,
            'data' => $performance,
            'meta' => [
                'total_vendors' => count($performance),
                'total_revenue' => array_sum(array_column($performance, 'revenue')),
                'total_commission' => array_sum(array_column($performance, 'commission')),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /**
     * Product performance report
     */
    public function productPerformance(Request $request)
    {
        $request->validate([
            'period' => 'sometimes|in:day,week,month,quarter,year',
            'date_from' => 'required_without:period|date',
            'date_to' => 'required_without:period|date|after_or_equal:date_from',
            'vendor_id' => 'nullable|exists:vendors,uuid',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $dateFrom = $request->get('date_from') ?? now()->startOfMonth();
        $dateTo = $request->get('date_to') ?? now()->endOfMonth();
        $limit = $request->get('limit', 50);

        $query = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('vendor_products', 'order_items.vendor_product_id', '=', 'vendor_products.id')
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->where('orders.status', '!=', 'cancelled');

        if ($request->has('vendor_id')) {
            $query->where('orders.vendor_id', $request->vendor_id);
        }

        $products = $query->select(
            'vendor_products.id',
            'vendor_products.name',
            'vendor_products.sku',
            'orders.vendor_id',
            DB::raw('SUM(order_items.qty_ordered) as quantity_sold'),
            DB::raw('SUM(order_items.row_total) as revenue'),
            DB::raw('AVG(order_items.price) as average_price'),
            DB::raw('COUNT(DISTINCT orders.id) as order_count'),
            DB::raw('SUM(order_items.tax_amount) as tax_collected')
        )
            ->groupBy('vendor_products.id', 'vendor_products.name', 'vendor_products.sku', 'orders.vendor_id')
            ->orderBy('revenue', 'desc')
            ->limit($limit)
            ->get();

        // Add vendor names
        foreach ($products as $product) {
            $vendor = Vendor::find($product->vendor_id);
            $product->vendor_name = $vendor?->company_name;
        }

        // Add summary
        $summary = [
            'total_products_sold' => $products->sum('quantity_sold'),
            'total_revenue' => $products->sum('revenue'),
            'average_price' => $products->avg('average_price'),
            'top_product' => $products->first(),
        ];

        return response()->json([
            'success' => true,
            'data' => $products,
            'summary' => $summary,
            'meta' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * Customer report
     */
    public function customerReport(Request $request)
    {
        $request->validate([
            'period' => 'sometimes|in:day,week,month,quarter,year',
            'date_from' => 'required_without:period|date',
            'date_to' => 'required_without:period|date|after_or_equal:date_from',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $dateFrom = $request->get('date_from') ?? now()->startOfMonth();
        $dateTo = $request->get('date_to') ?? now()->endOfMonth();
        $limit = $request->get('limit', 50);

        // Customer acquisition
        $newCustomers = User::where('user_type', 'customer')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->count();

        // Customer orders
        $customerOrders = DB::table('orders')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('customer_id')
            ->select(
                'customer_id',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(grand_total) as total_spent'),
                DB::raw('AVG(grand_total) as average_order_value')
            )
            ->groupBy('customer_id')
            ->orderBy('total_spent', 'desc')
            ->limit($limit)
            ->get();

        // Add customer details
        foreach ($customerOrders as $customerOrder) {
            $user = User::find($customerOrder->customer_id);
            $customerOrder->customer_name = $user?->full_name;
            $customerOrder->customer_email = $user?->email;
            $customerOrder->customer_since = $user?->created_at?->toDateString();
        }

        // Customer retention (repeat purchase rate)
        $repeatCustomers = DB::table('orders')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('customer_id')
            ->select('customer_id', DB::raw('COUNT(*) as order_count'))
            ->groupBy('customer_id')
            ->having('order_count', '>', 1)
            ->count();

        $totalCustomers = DB::table('orders')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('customer_id')
            ->distinct('customer_id')
            ->count('customer_id');

        $repeatRate = $totalCustomers > 0 ? ($repeatCustomers / $totalCustomers) * 100 : 0;

        // Customer lifetime value
        $ltv = DB::table('orders')
            ->whereNotNull('customer_id')
            ->select('customer_id', DB::raw('SUM(grand_total) as lifetime_value'))
            ->groupBy('customer_id')
            ->avg('lifetime_value');

        return response()->json([
            'success' => true,
            'data' => [
                'new_customers' => $newCustomers,
                'top_customers' => $customerOrders,
                'retention' => [
                    'repeat_customers' => $repeatCustomers,
                    'total_customers' => $totalCustomers,
                    'repeat_rate' => round($repeatRate, 2),
                ],
                'lifetime_value' => round($ltv, 2),
                'period' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
            ],
        ]);
    }

    /**
     * Export report to CSV
     */
    public function export(Request $request)
    {
        $request->validate([
            'report_type' => 'required|in:platform,financial,vendor_performance,product_performance,customer_report',
            'format' => 'sometimes|in:csv,excel',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $dateFrom = $request->date_from;
        $dateTo = $request->date_to;
        $reportType = $request->report_type;

        $data = match ($reportType) {
            'platform' => $this->reportService->getPlatformPerformance($dateFrom, $dateTo),
            'financial' => $this->getFinancialReportData($dateFrom, $dateTo),
            'vendor_performance' => $this->getVendorPerformanceData($dateFrom, $dateTo),
            'product_performance' => $this->getProductPerformanceData($dateFrom, $dateTo),
            'customer_report' => $this->getCustomerReportData($dateFrom, $dateTo),
            default => [],
        };

        $filename = $reportType.'_report_'.date('Y-m-d_His').'.csv';
        $csvContent = $this->arrayToCsv($data);

        return response()->json([
            'success' => true,
            'data' => [
                'filename' => $filename,
                'content' => base64_encode($csvContent),
                'mime_type' => 'text/csv',
            ],
        ]);
    }

    /**
     * Convert array to CSV
     */
    private function arrayToCsv(array $data): string
    {
        $handle = fopen('php://temp', 'w');

        if (! empty($data)) {
            // Get headers from first item
            $headers = array_keys((array) $data[0]);
            fputcsv($handle, $headers);

            foreach ($data as $row) {
                fputcsv($handle, (array) $row);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function getFinancialReportData($dateFrom, $dateTo)
    {
        // Implementation similar to financial report but returns array for CSV
        return [];
    }

    private function getVendorPerformanceData($dateFrom, $dateTo)
    {
        // Implementation similar to vendor performance report
        return [];
    }

    private function getProductPerformanceData($dateFrom, $dateTo)
    {
        // Implementation similar to product performance report
        return [];
    }

    private function getCustomerReportData($dateFrom, $dateTo)
    {
        // Implementation similar to customer report
        return [];
    }

    public function sales(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Admin sales report endpoint is not implemented yet.',
        ], 501);
    }

    public function downloadExport($jobId)
    {
        return response()->json([
            'success' => false,
            'message' => 'Export download is not implemented yet.',
            'job_id' => $jobId,
        ], 501);
    }
}

