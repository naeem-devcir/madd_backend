<?php

namespace App\Jobs\Jobs\Report;

use App\Models\Order\Order;
use App\Models\Vendor\Vendor;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateDailyReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;
    protected $reportType;

    public $tries = 2;
    public $timeout = 600; // 10 minutes

    /**
     * Create a new job instance.
     *
     * @param string|null $date (Y-m-d format)
     * @param string $reportType (summary, detailed, vendor)
     */
    public function __construct(?string $date = null, string $reportType = 'summary')
    {
        $this->date = $date ?? now()->toDateString();
        $this->reportType = $reportType;
    }

    /**
     * Execute the job.
     */
    public function handle(ReportService $reportService): void
    {
        try {
            $startDate = \Carbon\Carbon::parse($this->date)->startOfDay();
            $endDate = \Carbon\Carbon::parse($this->date)->endOfDay();

            switch ($this->reportType) {
                case 'summary':
                    $this->generateSummaryReport($startDate, $endDate);
                    break;
                case 'detailed':
                    $this->generateDetailedReport($startDate, $endDate);
                    break;
                case 'vendor':
                    $this->generateVendorReports($startDate, $endDate);
                    break;
                default:
                    $this->generateSummaryReport($startDate, $endDate);
            }

            Log::info('Daily report generated', [
                'date' => $this->date,
                'report_type' => $this->reportType,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate daily report', [
                'date' => $this->date,
                'report_type' => $this->reportType,
                'error' => $e->getMessage(),
            ]);
            $this->fail($e);
        }
    }

    /**
     * Generate summary report.
     */
    protected function generateSummaryReport($startDate, $endDate): void
    {
        // Platform summary
        $platformSummary = [
            'date' => $this->date,
            'total_orders' => Order::whereBetween('created_at', [$startDate, $endDate])->count(),
            'total_revenue' => Order::whereBetween('created_at', [$startDate, $endDate])->where('status', '!=', 'cancelled')->sum('grand_total'),
            'total_commission' => Order::whereBetween('created_at', [$startDate, $endDate])->where('status', '!=', 'cancelled')->sum('commission_amount'),
            'new_users' => User::whereBetween('created_at', [$startDate, $endDate])->count(),
            'new_vendors' => Vendor::whereBetween('created_at', [$startDate, $endDate])->count(),
            'active_vendors' => Vendor::where('status', 'active')->count(),
        ];

        // Store report
        $filename = "reports/daily/summary_{$this->date}.json";
        Storage::disk('s3')->put($filename, json_encode($platformSummary, JSON_PRETTY_PRINT));

        // Send to admin
        $this->sendReportToAdmins($platformSummary, 'summary');
    }

    /**
     * Generate detailed report.
     */
    protected function generateDetailedReport($startDate, $endDate): void
    {
        // Orders by hour
        $ordersByHour = Order::whereBetween('created_at', [$startDate, $endDate])
            ->select(DB::raw('HOUR(created_at) as hour'), DB::raw('COUNT(*) as count'), DB::raw('SUM(grand_total) as revenue'))
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        // Orders by status
        $ordersByStatus = Order::whereBetween('created_at', [$startDate, $endDate])
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(grand_total) as revenue'))
            ->groupBy('status')
            ->get();

        // Top products
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->select('order_items.product_name', DB::raw('SUM(order_items.qty_ordered) as quantity'), DB::raw('SUM(order_items.row_total) as revenue'))
            ->groupBy('order_items.product_name')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();

        // Top vendors
        $topVendors = Order::whereBetween('created_at', [$startDate, $endDate])
            ->select('vendor_id', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as revenue'))
            ->with('vendor')
            ->groupBy('vendor_id')
            ->orderBy('revenue', 'desc')
            ->limit(10)
            ->get();

        $detailedReport = [
            'date' => $this->date,
            'orders_by_hour' => $ordersByHour,
            'orders_by_status' => $ordersByStatus,
            'top_products' => $topProducts,
            'top_vendors' => $topVendors,
        ];

        // Store report
        $filename = "reports/daily/detailed_{$this->date}.json";
        Storage::disk('s3')->put($filename, json_encode($detailedReport, JSON_PRETTY_PRINT));
    }

    /**
     * Generate individual vendor reports.
     */
    protected function generateVendorReports($startDate, $endDate): void
    {
        $vendors = Vendor::where('status', 'active')->get();

        foreach ($vendors as $vendor) {
            $vendorOrders = Order::where('vendor_id', $vendor->getKey())
                ->whereBetween('created_at', [$startDate, $endDate])
                ->get();

            $vendorReport = [
                'vendor_id' => $vendor->getKey(),
                'vendor_name' => $vendor->company_name,
                'date' => $this->date,
                'total_orders' => $vendorOrders->count(),
                'total_revenue' => $vendorOrders->sum('grand_total'),
                'total_commission' => $vendorOrders->sum('commission_amount'),
                'net_payout' => $vendorOrders->sum('vendor_payout_amount'),
                'average_order_value' => $vendorOrders->avg('grand_total') ?? 0,
            ];

            // Store vendor report
            $filename = "reports/daily/vendors/{$vendor->getKey()}_{$this->date}.json";
            Storage::disk('s3')->put($filename, json_encode($vendorReport, JSON_PRETTY_PRINT));

            // Send report to vendor if they opted in
            if ($vendor->user && $vendor->user->preferences['daily_report'] ?? false) {
                \App\Jobs\Jobs\Notification\SendVendorReport::dispatch($vendor->user, $vendorReport);
            }
        }
    }

    /**
     * Send report to admins.
     */
    protected function sendReportToAdmins(array $report, string $type): void
    {
        $admins = User::role('admin')->get();

        foreach ($admins as $admin) {
            \App\Jobs\Jobs\Notification\SendAdminReport::dispatch($admin, $report, $type, $this->date);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Daily report generation failed', [
            'date' => $this->date,
            'report_type' => $this->reportType,
            'error' => $exception->getMessage(),
        ]);
    }
}
